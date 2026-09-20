<?php

namespace App\Actions\Maternity;

use App\Actions\Billing\CancelBillableItemAction;
use App\Enums\BillableItemStatus;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\EpisodeOrientation;
use App\Models\MaternityProcedure;
use App\Models\User;
use App\Services\Billing\ClinicalActBiller;
use App\Support\MaternityActProfile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige ou retire un acte Maternité déjà enregistré (ADR-140).
 *
 * ```text
 * corriger   la quantité et la précision — jamais l'acte : changer d'acte, c'est
 *            retirer celui-ci et en ajouter un autre
 * retirer    Soft Delete avec son auteur et son motif (ADR-009) : l'acte quitte
 *            la liste, rien n'est détruit, l'audit garde la trace
 * ```
 *
 * Deux garde-fous, revérifiés sur la ligne verrouillée — l'écran n'est jamais
 * la seule protection : la prise en charge doit être en cours, et l'acte
 * enregistré par un médecin reste intact pour les autres comptes.
 */
class ModifyMaternityProcedureAction
{
    public const REMOVAL_REASON = 'Acte retiré depuis le dossier Maternité';

    public function __construct(
        private readonly CancelBillableItemAction $cancelBillableItem,
        private readonly ClinicalActBiller $biller,
    ) {}

    /** @param array{quantity: mixed, notes?: ?string} $data */
    public function update(EpisodeOrientation $orientation, MaternityProcedure $procedure, array $data, User $actor): MaternityProcedure
    {
        return DB::transaction(function () use ($orientation, $procedure, $data, $actor): MaternityProcedure {
            $locked = $this->lock($orientation, $procedure, $actor);
            $notes = filled($data['notes'] ?? null) ? trim($data['notes']) : null;

            // Même règle qu'à l'enregistrement : « Autres » ne se comprend pas sans sa description.
            if ($locked->procedure_code === MaternityActProfile::OTHER_CODE && $notes === null) {
                throw ValidationException::withMessages([
                    'notes' => 'Précisez l’acte « Autres » : il ne se comprend pas sans sa description.',
                ]);
            }

            $this->rebillIfQuantityChanged($locked, $data['quantity'], $orientation, $actor);

            $locked->fill([
                'quantity' => $data['quantity'],
                'notes' => $notes,
                'edited_by' => $actor->id,
                'edited_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function remove(EpisodeOrientation $orientation, MaternityProcedure $procedure, User $actor): void
    {
        DB::transaction(function () use ($orientation, $procedure, $actor): void {
            $locked = $this->lock($orientation, $procedure, $actor);
            $this->releaseBilling($locked, $actor);
            $locked->delete_reason = self::REMOVAL_REASON;
            $locked->delete();
        });
    }

    private function lock(EpisodeOrientation $orientation, MaternityProcedure $procedure, User $actor): MaternityProcedure
    {
        $orientation = EpisodeOrientation::query()->with('episode')->lockForUpdate()->findOrFail($orientation->id);

        if ($orientation->destination_module !== CatalogModule::Maternity
            || $orientation->status !== EpisodeOrientationStatus::InProgress
            || $orientation->episode->status !== EpisodeStatus::Open) {
            throw ValidationException::withMessages(['procedure' => 'Un acte ne se modifie que pendant une prise en charge Maternité active.']);
        }

        $locked = MaternityProcedure::query()->with('record')->lockForUpdate()->findOrFail($procedure->id);

        // L'acte d'un autre passage n'est pas celui-ci, quel que soit l'identifiant envoyé.
        abort_unless($locked->record->episode_id === $orientation->episode_id, 404);

        if ($locked->isLockedFor($actor)) {
            throw ValidationException::withMessages([
                'procedure' => 'Cet acte a été enregistré par un médecin : il ne peut pas être modifié depuis la Maternité.',
            ]);
        }

        return $locked;
    }

    /**
     * Retirer un acte retire ce que la Maternité avait elle-même mis au compte.
     *
     * ```text
     * OWN + en attente     annulé — sinon un acte retiré resterait à payer
     * PLANNED              jamais touché : c'est la facturation de la Réception
     * déjà sur une facture jamais détricoté ici — seule la Réception/Caisse
     *                      corrige un montant facturé (ADR-012)
     * ```
     */
    private function releaseBilling(MaternityProcedure $procedure, User $actor): void
    {
        $item = $procedure->billableItem;

        if ($procedure->billing_origin !== 'OWN' || $item?->status !== BillableItemStatus::Pending) {
            return;
        }

        $this->cancelBillableItem->execute($item, 'Acte retiré du dossier Maternité.', $actor);
    }

    /**
     * Changer la quantité d'un acte déjà facturé change ce que doit le patient.
     *
     * Tant que la facturation est celle de la Maternité et qu'elle n'est pas sur
     * une facture, elle est annulée puis refaite à la nouvelle quantité. Sinon,
     * la quantité ne bouge plus d'ici : la facturation appartient alors à la
     * Réception/Caisse, et le dire vaut mieux qu'une facture qui ne suit pas.
     */
    private function rebillIfQuantityChanged(MaternityProcedure $procedure, mixed $newQuantity, EpisodeOrientation $orientation, User $actor): void
    {
        $item = $procedure->billableItem;

        if (! $item || (float) $procedure->quantity === (float) $newQuantity) {
            return;
        }

        if ($procedure->billing_origin !== 'OWN' || $item->status !== BillableItemStatus::Pending) {
            throw ValidationException::withMessages([
                'quantity' => 'Cet acte est déjà porté au compte du patient : sa quantité ne se modifie plus depuis la Maternité. Retirez-le et ajoutez-le de nouveau si nécessaire, ou demandez à la Réception de corriger la facturation.',
            ]);
        }

        $this->cancelBillableItem->execute($item, 'Quantité corrigée dans le dossier Maternité.', $actor);

        $billable = $this->biller->bill(
            $orientation->episode,
            $procedure->catalogItem,
            'maternity_procedure:'.$procedure->uuid.':'.Str::uuid(),
            $actor,
            $procedure,
            $newQuantity,
        );

        $procedure->fill([
            'billable_item_id' => $billable?->getKey(),
            'billing_origin' => $billable ? 'OWN' : null,
        ]);
    }
}
