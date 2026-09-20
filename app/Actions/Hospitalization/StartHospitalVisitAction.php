<?php

namespace App\Actions\Hospitalization;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeMedicalStatus;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-148 — la visite de service : une vraie rencontre pendant le séjour.
 *
 * Un patient hospitalisé continue d'être examiné, prescrit, envoyé au
 * laboratoire. Rien de tout cela n'était possible : la consultation qui a
 * demandé l'hospitalisation est le plus souvent close (ADR-076), et le séjour
 * ne portait qu'une fiche de régime.
 *
 * Une visite est donc une **Consultation**, exactement comme une rencontre
 * ordinaire : tout le circuit existant la sert sans une ligne dupliquée —
 * diagnostic, ordonnance et sa réservation FEFO, analyses, imagerie, ordre de
 * soins, avec leur facturation et leurs droits d'aujourd'hui.
 *
 * Elle n'invente aucun chemin : `CreateEpisodeOrientationAction` puis
 * `AcceptMedicineOrientationAction`, comme la Maternité qui renvoie au médecin
 * (ADR-135). Son `active_key` garantit qu'un passage n'a jamais deux
 * orientations Médecine actives : rouvrir une visite déjà ouverte la retrouve
 * au lieu d'en créer une seconde.
 */
class StartHospitalVisitAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $orient,
        private readonly AcceptMedicineOrientationAction $accept,
    ) {}

    public function execute(HospitalStay $stay, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($stay, $actor): EpisodeOrientation {
            $locked = HospitalStay::query()->with('episode')->lockForUpdate()->findOrFail($stay->getKey());

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'visit' => 'Ce séjour est terminé : sa sortie est déjà prononcée.',
                ]);
            }

            $orientation = $this->orient->execute(
                $locked->episode,
                CatalogModule::Hospitalization,
                CatalogModule::Medicine,
                $actor,
                'Visite de service — patient hospitalisé.',
            );

            // Prise en charge immédiate : le patient est dans un lit, pas dans
            // la file d'attente. Il n'y apparaît donc jamais « à prendre ».
            $orientation = $this->accept->execute($orientation, $actor);

            // `AcceptMedicineOrientationAction` remet le passage « en soins » :
            // c'est juste pour une arrivée ordinaire, faux ici. Le patient
            // reste hospitalisé tant que sa sortie n'est pas prononcée
            // (ADR-113) — une visite ne le fait pas descendre du lit.
            // `refresh()` d'abord : l'instance en mémoire porte encore
            // HOSPITALIZED, donc réécrire la même valeur ne serait pas « dirty »
            // et Eloquent n'enverrait aucun UPDATE.
            $locked->episode->refresh()->forceFill([
                'medical_status' => EpisodeMedicalStatus::Hospitalized,
            ])->save();

            return $orientation;
        });
    }
}
