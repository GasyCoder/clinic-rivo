<?php

namespace App\Actions\Episode;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Maternity\AcceptMaternityOrientationAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Support\EpisodeEntryPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ADR-177 — la vraie prise en charge d'un passage par un service.
 *
 * Voir un passage ne le prend pas en charge : tant que personne n'a fait ce
 * geste, aucun professionnel n'en est responsable dans son service. Ce geste
 * est ici, et il est le même pour Soins, Médecine et Maternité :
 *
 * ```text
 * une vraie orientation attend ce service   elle est acceptée (urgence, transmission…)
 * aucune orientation vers ce service        elle est créée, puis acceptée à l'instant
 * le service a déjà le patient              rien n'est refait : on retrouve la prise en charge
 * ```
 *
 * L'acceptation reste celle de chaque service — `AcceptCareOrientationAction`,
 * `AcceptMedicineOrientationAction` (qui ouvre la consultation),
 * `AcceptMaternityOrientationAction` : les garde-fous existants s'appliquent
 * tels quels.
 *
 * Trois refus, qui protègent le dossier :
 *
 * - un passage dont l'accueil n'est pas terminé à la Réception, ou dont le
 *   parcours clinique est clos (en attente de règlement) — il n'est plus à
 *   prendre ;
 * - un service qui a déjà pris en charge **et terminé** ce passage : le
 *   reprendre en double n'est pas un geste ; une nouvelle demande passe par
 *   une vraie orientation (ordre de soins, transmission), une consultation
 *   close se rouvre (ADR-096) ;
 * - les Soins, devant un patient attendu directement en Médecine
 *   (`EpisodeEntryPath`) : son besoin et la suggestion de l'accueil ne désignent
 *   que le médecin, et une prestation MEDICINE_DIRECT ne passe pas
 *   artificiellement par les Soins (ADR-030). Un soin demandé par le médecin —
 *   une vraie orientation en attente — et l'urgence n'y sont jamais soumis. La
 *   Médecine, elle, n'est jamais refusée : devant un patient attendu aux Soins,
 *   l'écran le lui rappelle, et la décision reste la sienne.
 */
class TakeChargeOfEpisodeAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly AcceptCareOrientationAction $acceptCare,
        private readonly AcceptMedicineOrientationAction $acceptMedicine,
        private readonly AcceptMaternityOrientationAction $acceptMaternity,
    ) {}

    public function execute(Episode $episode, CatalogModule $module, User $actor): EpisodeOrientation
    {
        if (! in_array($module, [CatalogModule::Care, CatalogModule::Medicine, CatalogModule::Maternity], true)) {
            throw new InvalidArgumentException("La prise en charge depuis le tableau des passages ne concerne pas le module {$module->value}.");
        }

        return DB::transaction(function () use ($episode, $module, $actor): EpisodeOrientation {
            $locked = Episode::query()->lockForUpdate()->findOrFail($episode->getKey());

            $orientations = EpisodeOrientation::query()
                ->where('episode_id', $locked->getKey())
                ->where('destination_module', $module->value)
                ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value)
                ->lockForUpdate()
                ->get();

            $active = $orientations->first(fn (EpisodeOrientation $orientation) => $orientation->status->isActive());

            // Le service a déjà le patient : un double clic, une page restée
            // ouverte — on retrouve la prise en charge, sans rien refaire.
            if ($active?->status === EpisodeOrientationStatus::InProgress) {
                // Une prise en charge Médecine sans dossier de consultation (une
                // ligne ancienne) : l'acceptation l'ouvre, sans rien réaccepter.
                if ($module === CatalogModule::Medicine && ! $active->consultation()->exists()) {
                    return $this->acceptMedicine->execute($active, $actor);
                }

                return $active->load('episode.patient');
            }

            $this->ensureOpenToTake($locked, $active);

            if ($active === null) {
                if ($orientations->contains(fn (EpisodeOrientation $orientation) => $orientation->status === EpisodeOrientationStatus::Completed)) {
                    throw ValidationException::withMessages([
                        'episode' => "{$module->label()} a déjà pris en charge et terminé ce passage. Ouvrez la prise en charge terminée pour la relire ; une nouvelle demande passe par une vraie orientation.",
                    ]);
                }

                if (EpisodeEntryPath::guard($locked, $module)['blocking'] ?? false) {
                    throw ValidationException::withMessages(['episode' => EpisodeEntryPath::refusalMessage()]);
                }

                $active = $this->createOrientation->execute(
                    $locked,
                    CatalogModule::Reception,
                    $module,
                    $actor,
                    'Pris en charge depuis les passages en cours.',
                );
            }

            return match ($module) {
                CatalogModule::Care => $this->acceptCare->execute($active, $actor),
                CatalogModule::Medicine => $this->acceptMedicine->execute($active, $actor),
                CatalogModule::Maternity => $this->acceptMaternity->execute($active, $actor),
            };
        });
    }

    /**
     * Une vraie orientation en attente se prend toujours : c'est une demande
     * adressée à ce service. Sans elle, le passage doit être ouvert, accueilli,
     * et son parcours clinique pas encore clos.
     */
    private function ensureOpenToTake(Episode $episode, ?EpisodeOrientation $pending): void
    {
        if ($episode->status !== EpisodeStatus::Open) {
            throw ValidationException::withMessages([
                'episode' => 'Ce passage n’est plus ouvert : il ne peut plus être pris en charge.',
            ]);
        }

        if ($pending !== null) {
            return;
        }

        if ($episode->administrative_status === EpisodeAdministrativeStatus::PendingSettlement) {
            throw ValidationException::withMessages([
                'episode' => 'Le parcours clinique de ce passage est terminé : il n’attend plus que sa sortie administrative à la Réception.',
            ]);
        }

        $received = $episode->service_plan_finalized_at !== null
            || $episode->priority === EpisodePriority::Emergency
            || EpisodeOrientation::query()
                ->where('episode_id', $episode->getKey())
                ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value)
                ->exists();

        if (! $received) {
            throw ValidationException::withMessages([
                'episode' => 'L’accueil de ce passage n’est pas encore terminé à la Réception.',
            ]);
        }
    }
}
