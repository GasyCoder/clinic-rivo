<?php

namespace App\Services\Laboratory;

use App\Enums\BillableItemStatus;
use App\Enums\EpisodePriority;
use App\Enums\HospitalStayStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Support\Money;

/**
 * ADR-214 — le contrôle du règlement avant le prélèvement (CDC §14 : « Payé ?
 * Non → En attente »). Le Laboratoire le lit, il n'encaisse jamais (ADR-014) :
 * aucun montant n'est servi, seulement l'état de chaque analyse.
 *
 * Jamais bloqués : une urgence (ADR-021), un patient hospitalisé — sa facture
 * rejoint la sortie (ADR-162) —, une analyse prise en charge à 100 %.
 *
 * Une analyse que personne n'a pu facturer (tarif absent…) ne bloque pas : il
 * n'y a rien à régler, et l'écran le dit pour que la Réception régularise
 * (ADR-103) plutôt que de laisser le patient attendre un paiement impossible.
 */
class LabPaymentClearance
{
    public const SETTLED = 'SETTLED';

    public const NOTHING_DUE = 'NOTHING_DUE';

    public const DUE = 'DUE';

    public const TO_INVOICE = 'TO_INVOICE';

    public const NOT_BILLED = 'NOT_BILLED';

    public const CANCELLED = 'CANCELLED';

    private const BLOCKING = [self::DUE, self::TO_INVOICE];

    private const LABELS = [
        self::SETTLED => 'Réglée',
        self::NOTHING_DUE => 'Prise en charge',
        self::DUE => 'À régler à la Caisse',
        self::TO_INVOICE => 'À facturer puis régler à la Caisse',
        self::NOT_BILLED => 'Non facturée — à régulariser par la Réception',
        self::CANCELLED => 'Prestation annulée',
    ];

    public const EXEMPT_EMERGENCY = 'EMERGENCY';

    public const EXEMPT_HOSPITALIZED = 'HOSPITALIZED';

    private const EXEMPTION_LABELS = [
        self::EXEMPT_EMERGENCY => 'Urgence : le prélèvement n’attend pas le règlement.',
        self::EXEMPT_HOSPITALIZED => 'Patient hospitalisé : le règlement suit la sortie.',
    ];

    /** Ce qu'il faut charger pour lire le contrôle sans requête par ligne. */
    public const RELATIONS = [
        'items.billableItem.invoiceLine.invoice',
        'episode.hospitalStays',
    ];

    /** @return array<string, mixed> */
    public function for(LabRequest $request): array
    {
        $request->loadMissing(self::RELATIONS);
        $exemption = $this->exemption($request);

        $lines = $request->items->sortBy('id')->map(fn (LabRequestItem $item) => [
            'uuid' => $item->uuid,
            'name' => $item->catalog_item_name_snapshot,
            'state' => $state = $this->itemState($item->billableItem),
            'label' => self::LABELS[$state],
            'blocking' => in_array($state, self::BLOCKING, true),
            'needs_regularization' => $state === self::NOT_BILLED,
        ])->values();

        $due = $lines->where('blocking', true)->values();
        $cleared = $exemption !== null || $due->isEmpty();

        return [
            'cleared' => $cleared,
            'exemption' => $exemption,
            'exemption_label' => $exemption ? self::EXEMPTION_LABELS[$exemption] : null,
            'lines' => $lines->all(),
            'due_count' => $due->count(),
            'unbilled_count' => $lines->where('needs_regularization', true)->count(),
            'summary' => match (true) {
                $exemption !== null => self::EXEMPTION_LABELS[$exemption],
                $due->isEmpty() => 'Rien à régler : la demande peut être prélevée.',
                default => 'À régler à la Caisse avant le prélèvement : '.$due->pluck('name')->join(', ', ' et ').'.',
            },
        ];
    }

    /** Pourquoi la demande passe sans attendre le règlement, s'il y a lieu. */
    public function exemption(LabRequest $request): ?string
    {
        $request->loadMissing(['episode.hospitalStays']);
        $episode = $request->episode;

        if ($episode?->priority === EpisodePriority::Emergency) {
            return self::EXEMPT_EMERGENCY;
        }

        if ($request->hospital_stay_id !== null
            || $episode?->hospitalStays->contains(fn ($stay) => $stay->status === HospitalStayStatus::Active)) {
            return self::EXEMPT_HOSPITALIZED;
        }

        return null;
    }

    public function itemState(?BillableItem $billable): string
    {
        if ($billable === null) {
            return self::NOT_BILLED;
        }

        if ($billable->status === BillableItemStatus::Cancelled) {
            return self::CANCELLED;
        }

        if (Money::toMinor((string) ($billable->patient_amount ?? $billable->total_amount ?? '0')) === 0) {
            return self::NOTHING_DUE;
        }

        if ($billable->status !== BillableItemStatus::Invoiced) {
            return self::TO_INVOICE;
        }

        $invoice = $billable->invoiceLine?->invoice;

        return $invoice !== null
            && in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Covered], true)
            && Money::toMinor((string) ($invoice->balance_amount ?? '0')) === 0
            ? self::SETTLED
            : self::DUE;
    }
}
