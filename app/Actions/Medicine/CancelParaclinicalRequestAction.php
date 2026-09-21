<?php

namespace App\Actions\Medicine;

use App\Models\Consultation;
use App\Models\HospitalStay;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\User;
use App\Services\Billing\ParaclinicalBillingRelease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retirer **une** demande d'examen, sans toucher aux autres.
 *
 * Seule l'annulation en bloc existait : répondre « Non » à la question en
 * tête d'étape retirait toutes les demandes d'un coup
 * (`DecideComplementaryExamsAction`, ADR-079). Un médecin qui avait demandé
 * une NFS et une échographie, et ne voulait renoncer qu'à l'échographie,
 * n'avait aucun chemin.
 *
 * L'annulation est un **changement d'état**, jamais une suppression ni une
 * ligne miroir (ADR-010) : la demande garde son auteur, sa date et son
 * numéro, et reste lisible dans le dossier avec sa trace de retrait.
 *
 * Une demande qui porte déjà un résultat n'est jamais retirée : le service a
 * fait le travail, et l'effacer de la file reviendrait à nier un acte
 * réalisé. C'est exactement la règle que l'ADR-079 pose déjà pour
 * l'annulation en bloc ; elle est reprise ici, sur la même colonne.
 *
 * ADR-163 — retirer une demande retire aussi ce qu'elle a porté au compte du
 * patient (ADR-105), ce que ce chemin oubliait ; et une demande écrite depuis
 * le séjour (ADR-162) se retire depuis le séjour, par la même règle.
 */
class CancelParaclinicalRequestAction
{
    public function __construct(private readonly ParaclinicalBillingRelease $billingRelease) {}

    /**
     * @throws ValidationException
     */
    public function execute(
        Consultation $consultation,
        LabRequest|ImagingRequest $request,
        ?string $reason,
        User $actor,
    ): LabRequest|ImagingRequest {
        return DB::transaction(function () use ($consultation, $request, $reason, $actor) {
            $locked = $this->lock($request);

            // Une demande d'un autre passage n'a rien à faire ici, même avec
            // un identifiant valide.
            if ((int) $locked->consultation_id !== (int) $consultation->getKey()) {
                throw ValidationException::withMessages([
                    'request' => 'Cette demande n’appartient pas à cette consultation.',
                ]);
            }

            if (! $consultation->isEditable()) {
                throw ValidationException::withMessages([
                    'request' => 'Cette consultation est clôturée : ses demandes ne peuvent plus être retirées.',
                ]);
            }

            return $this->withdraw($locked, $reason, $actor);
        });
    }

    /**
     * ADR-163 — la même règle, depuis le séjour : tant que le patient est au lit.
     *
     * @throws ValidationException
     */
    public function executeForStay(
        HospitalStay $stay,
        LabRequest|ImagingRequest $request,
        ?string $reason,
        User $actor,
    ): LabRequest|ImagingRequest {
        return DB::transaction(function () use ($stay, $request, $reason, $actor) {
            $locked = $this->lock($request);

            if ((int) $locked->hospital_stay_id !== (int) $stay->getKey()) {
                throw ValidationException::withMessages([
                    'request' => 'Cette demande n’a pas été faite depuis ce séjour.',
                ]);
            }

            if (! $stay->fresh()->isActive()) {
                throw ValidationException::withMessages([
                    'request' => 'Ce séjour est terminé : ses demandes ne peuvent plus être retirées.',
                ]);
            }

            return $this->withdraw($locked, $reason, $actor);
        });
    }

    private function lock(LabRequest|ImagingRequest $request): LabRequest|ImagingRequest
    {
        /** @var LabRequest|ImagingRequest $locked */
        $locked = $request->newQuery()->lockForUpdate()->findOrFail($request->getKey());
        $locked->load('items');

        return $locked;
    }

    private function withdraw(LabRequest|ImagingRequest $locked, ?string $reason, User $actor): LabRequest|ImagingRequest
    {
        if ($locked->cancelled_at !== null) {
            throw ValidationException::withMessages([
                'request' => 'Cette demande a déjà été retirée.',
            ]);
        }

        if ($locked->items->contains(fn (Model $item) => $item->resulted_at !== null)) {
            throw ValidationException::withMessages([
                'request' => 'Cette demande porte déjà un résultat : elle ne peut plus être retirée.',
            ]);
        }

        $locked->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $actor->getKey(),
            // Le motif reste facultatif : « finalement inutile » est un
            // énoncé complet, et l'exiger pousserait au remplissage.
            'cancel_reason' => filled($reason) ? trim($reason) : null,
        ])->save();

        $this->billingRelease->release($locked, $actor);

        return $locked->fresh('items');
    }
}
