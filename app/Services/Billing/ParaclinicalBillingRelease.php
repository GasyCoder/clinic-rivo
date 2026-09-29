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

    public function release(LabRequest|ImagingRequest $request, User $actor, string $reason = 'Examen complémentaire retiré par le médecin.'): void
    {
        foreach ($request->items()->with('billableItem')->get() as $line) {
            $this->releaseLine($line, $actor, $reason);
        }
    }

    /**
     * ADR-220 — une seule ligne : l'analyse retirée d'une demande par le
     * laboratoire. Renvoie vrai quand une facturation encore en attente a été
     * annulée ; faux quand il n'y avait rien à annuler ici (déjà sur facture,
     * facturée par la Réception, ou jamais facturée).
     */
    public function releaseLine(LabRequestItem|ImagingRequestItem $line, User $actor, string $reason): bool
    {
        $billable = $line->relationLoaded('billableItem') ? $line->billableItem : $line->billableItem()->first();

        if ($billable?->status !== BillableItemStatus::Pending || ! self::isOwnKey($line, $billable->idempotency_key)) {
            return false;
        }

        $this->cancelBillableItem->execute($billable, $reason, $actor);

        return true;
    }

    /**
     * La facturation que la ligne a créée elle-même : sa clé, ou — après une
     * restauration de la corbeille (ADR-220) — sa clé suivie d'un rang.
     */
    public static function isOwnKey(LabRequestItem|ImagingRequestItem $line, ?string $key): bool
    {
        $own = self::ownKey($line);

        return $key === $own || ($key !== null && str_starts_with($key, $own.':'));
    }

    /** La clé que la demande donne à ce qu'elle facture elle-même (ADR-105). */
    public static function ownKey(LabRequestItem|ImagingRequestItem $line): string
    {
        return ($line instanceof ImagingRequestItem ? 'imaging_request_item:' : 'lab_request_item:').$line->uuid;
    }
}
