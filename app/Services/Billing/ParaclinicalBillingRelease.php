<?php

namespace App\Services\Billing;

use App\Actions\Billing\CancelBillableItemAction;
use App\Enums\BillableItemStatus;
use App\Models\ImagingRequest;
use App\Models\ImagingRequestItem;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;

/**
 * ADR-105 / ADR-109 — ce que retirer une demande d'examen retire du compte du patient.
 *
 * Une seule règle pour les trois chemins de retrait (« Non » en tête de
 * Paraclinique, une demande seule en consultation, une demande depuis le
 * séjour) : sans elle, un examen annulé restait à payer.
 *
 *   - Seuls les éléments encore `PENDING` sont annulés : un élément déjà porté
 *     sur une facture ne se détricote pas ici — seule la Réception/Caisse
 *     touche un montant facturé (ADR-012).
 *   - Seuls ceux que la demande a **elle-même** facturés : quand elle avait
 *     rattaché la prestation que la Réception avait déjà facturée à l'arrivée
 *     (ADR-109), cette prestation reste celle de la Réception — le patient est
 *     venu pour elle, et la retirer ici effacerait une décision de l'accueil.
 *     La clé d'idempotence dit qui l'a créée.
 */
class ParaclinicalBillingRelease
{
    public function __construct(private readonly CancelBillableItemAction $cancelBillableItem) {}

    public function release(LabRequest|ImagingRequest $request, User $actor): void
    {
        foreach ($request->items()->with('billableItem')->get() as $line) {
            $billable = $line->billableItem;

            if ($billable?->status !== BillableItemStatus::Pending
                || $billable->idempotency_key !== self::ownKey($line)) {
                continue;
            }

            $this->cancelBillableItem->execute(
                $billable,
                'Examen complémentaire retiré par le médecin.',
                $actor,
            );
        }
    }

    /** La clé que la demande donne à ce qu'elle facture elle-même (ADR-105). */
    public static function ownKey(LabRequestItem|ImagingRequestItem $line): string
    {
        return ($line instanceof ImagingRequestItem ? 'imaging_request_item:' : 'lab_request_item:').$line->uuid;
    }
}
