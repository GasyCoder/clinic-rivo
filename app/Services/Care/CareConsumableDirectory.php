<?php

namespace App\Services\Care;

use App\Enums\BillableItemStatus;
use App\Enums\CareConsumableRequestStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Enums\MedicineStockReservationStatus;
use App\Models\CareConsumableRequest;
use App\Models\CareConsumableRequestLine;
use App\Models\Medicine;
use App\Models\MedicineLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ADR-072 — single projection of the Soins ⇄ Pharmacie consumable circuit,
 * consumed by the Soins worksheet and by the Pharmacy workspace so both
 * sides always read the same facts.
 *
 * A clinician never sees or enters an amount (ADR-036): `forOrientation()`
 * — the Soins worksheet — therefore carries no price at all, and that is
 * enforced by `present()` taking `$withBilling = false` by default rather
 * than by each caller remembering to strip it.
 *
 * `pharmacyQueue()` does ask for it. The Pharmacy never cashes anything
 * (ADR-013), but its stock is what leaves the shelf: it must be able to see
 * that the consumable reached the patient's account — and above all that a
 * line did *not*, which is the only way one ends up free. Same read-only
 * financial status the dispensing queue already shows (ADR-014).
 */
class CareConsumableDirectory
{
    /**
     * Consumables a nurse may declare: parapharmacy only — never a
     * medicine, which is what makes the client's "Soins ne donne jamais un
     * médicament" rule structural rather than cosmetic.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function selectableConsumables(): Collection
    {
        $today = CarbonImmutable::today();

        return Medicine::query()
            ->where('active', true)
            ->where('form', MedicineForm::ParapharmacyConsumable->value)
            ->whereHas('catalogItem', fn ($query) => $query
                ->where('type', CatalogItemType::Medicine->value)
                ->where('module', CatalogModule::Pharmacy->value)
                ->where('stockable', true))
            ->with([
                'catalogItem:id,uuid,code,name,unit',
                'lots' => fn ($query) => $query
                    ->where('active', true)
                    ->withSum([
                        'reservations as prescription_reserved_quantity' => fn ($reservation) => $reservation
                            ->where('status', MedicineStockReservationStatus::Reserved->value),
                    ], 'remaining_quantity')
                    ->withSum([
                        'counterReservations as counter_reserved_quantity' => fn ($reservation) => $reservation
                            ->where('status', MedicineStockReservationStatus::Reserved->value),
                    ], 'remaining_quantity')
                    ->orderBy('expires_at'),
            ])
            ->get()
            ->map(function (Medicine $medicine) use ($today): array {
                $usable = $medicine->lots->filter(
                    fn (MedicineLot $lot) => $lot->expires_at->gte($today),
                );
                $available = $usable->sum(fn (MedicineLot $lot) => max(
                    0,
                    $lot->quantity_on_hand
                        - (int) ($lot->prescription_reserved_quantity ?? 0)
                        - (int) ($lot->counter_reserved_quantity ?? 0),
                ));

                return [
                    'medicine_uuid' => $medicine->uuid,
                    'code' => $medicine->catalogItem->code,
                    'name' => $medicine->catalogItem->name,
                    'unit' => $medicine->catalogItem->unit,
                    'available_quantity' => $available,
                    'available' => $available > 0,
                ];
            })
            ->sortBy(fn (array $row) => str($row['name'])->lower()->toString())
            ->values();
    }

    /**
     * Requests declared during one nursing visit, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forOrientation(int $orientationId): Collection
    {
        return $this->present(
            CareConsumableRequest::query()
                ->where('care_orientation_id', $orientationId)
                ->with($this->relations())
                ->latest('requested_at')
                ->latest('id')
                ->get(),
        );
    }

    /**
     * The Pharmacy queue: everything still to serve, plus what was served
     * recently so the pharmacist can check their own work.
     *
     * @return array{summary: array<string, int>, requests: array<int, array<string, mixed>>}
     */
    public function pharmacyQueue(int $servedHistoryLimit = 20): array
    {
        $open = CareConsumableRequest::query()
            ->whereIn('status', [
                CareConsumableRequestStatus::Pending->value,
                CareConsumableRequestStatus::PartiallyServed->value,
            ])
            ->with($this->relations(withBilling: true))
            ->oldest('requested_at')
            ->get();

        $recent = CareConsumableRequest::query()
            ->whereIn('status', [
                CareConsumableRequestStatus::Served->value,
                CareConsumableRequestStatus::Cancelled->value,
            ])
            ->with($this->relations(withBilling: true))
            ->latest('updated_at')
            ->limit($servedHistoryLimit)
            ->get();

        return [
            'summary' => [
                'pending' => $open->where('status', CareConsumableRequestStatus::Pending)->count(),
                'partially_served' => $open->where('status', CareConsumableRequestStatus::PartiallyServed)->count(),
                'lines_to_serve' => $open->sum(
                    fn (CareConsumableRequest $request) => $request->lines->sum(
                        fn (CareConsumableRequestLine $line) => $line->remainingQuantity(),
                    ),
                ),
                // Le seul chemin par lequel un consommable finit réellement
                // gratuit : la facturation a échoué en silence (tarif de
                // vente absent, contexte financier du passage non résolu) et
                // plus rien ne le signalait ensuite. Compté sur l'ensemble
                // des demandes projetées, servies comprises — une fois le
                // produit sorti du stock, l'oubli ne se répare plus tout
                // seul.
                'unbilled_lines' => $open->concat($recent)->sum(
                    fn (CareConsumableRequest $request) => $request->lines->filter(
                        fn (CareConsumableRequestLine $line) => $this->lineBilling($line)['state'] === 'NOT_BILLED',
                    )->count(),
                ),
            ],
            'requests' => $this->present($open->concat($recent), withBilling: true)->all(),
        ];
    }

    /** Number of Soins requests still awaiting a Pharmacy stock exit. */
    public function openRequestCount(): int
    {
        return CareConsumableRequest::query()
            ->whereIn('status', [
                CareConsumableRequestStatus::Pending->value,
                CareConsumableRequestStatus::PartiallyServed->value,
            ])
            ->count();
    }

    /** @return array<int, string|callable> */
    private function relations(bool $withBilling = false): array
    {
        return [
            'lines.allocations.medicineLot:id,uuid,lot_number,expires_at',
            'requester:id,name',
            'server:id,name',
            'canceller:id,name',
            'episode:id,uuid,episode_number,patient_id',
            'episode.patient:id,uuid,patient_number,first_name,last_name',
            ...($withBilling ? [
                'lines.billableItem:id,uuid,status,quantity,unit_price,total_amount,currency',
                'lines.billableItem.invoiceLine:id,billable_item_id,invoice_id',
                'lines.billableItem.invoiceLine.invoice:id,uuid,invoice_number,status,total_amount,paid_amount,balance_amount',
                // `billable` distinguishes « the catalogue says this product
                // is not charged » from « nobody managed to charge it ».
                'lines.medicine:id,catalog_item_id',
                'lines.medicine.catalogItem:id,billable',
            ] : []),
        ];
    }

    /**
     * @param  Collection<int, CareConsumableRequest>  $requests
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $requests, bool $withBilling = false): Collection
    {
        return $requests->map(fn (CareConsumableRequest $request) => [
            'uuid' => $request->uuid,
            'request_number' => $request->request_number,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'can_be_served' => $request->status->canBeServed(),
            'can_be_cancelled' => $request->status->canBeCancelled(),
            'notes' => $request->notes,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'requested_by' => $request->requester?->name,
            'served_at' => $request->served_at?->toIso8601String(),
            'served_by' => $request->server?->name,
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
            'cancelled_by' => $request->canceller?->name,
            'cancellation_reason' => $request->cancellation_reason,
            'episode' => $request->episode ? [
                'uuid' => $request->episode->uuid,
                'episode_number' => $request->episode->episode_number,
                'patient_number' => $request->episode->patient?->patient_number,
                'patient_name' => trim(sprintf(
                    '%s %s',
                    $request->episode->patient?->last_name ?? '',
                    $request->episode->patient?->first_name ?? '',
                )) ?: null,
            ] : null,
            // Jamais exposé au poste de soins (ADR-036) : le drapeau est
            // faux par défaut, il n'y a donc rien à penser à retirer.
            'billing' => $withBilling ? $this->requestBilling($request) : null,
            'lines' => $request->lines->map(fn (CareConsumableRequestLine $line) => [
                'uuid' => $line->uuid,
                'name' => $line->medicine_name,
                'code' => $line->medicine_code,
                'unit' => $line->unit,
                'quantity_requested' => $line->quantity_requested,
                'quantity_served' => $line->quantity_served,
                'remaining_quantity' => $line->remainingQuantity(),
                'billing' => $withBilling ? $this->lineBilling($line) : null,
                'allocations' => $line->allocations->map(fn ($allocation) => [
                    'uuid' => $allocation->uuid,
                    'quantity' => $allocation->quantity,
                    'lot_number' => $allocation->medicineLot?->lot_number,
                    'expires_at' => $allocation->medicineLot?->expires_at?->toDateString(),
                    'served_at' => $allocation->served_at?->toIso8601String(),
                ])->values(),
            ])->values(),
        ])->values();
    }

    /**
     * Ce qu'une ligne a réellement produit côté compte patient.
     *
     * Cinq états, parce que quatre ne suffisent pas à distinguer ce qui se
     * répare de ce qui n'a pas lieu d'être :
     *
     *   INVOICED      portée sur une facture du passage
     *   PENDING       chiffrée, en attente d'une facture à encaisser
     *   CANCELLED     la prestation a été annulée après coup
     *   NOT_BILLABLE  le catalogue dit que ce produit n'est pas facturé
     *   NOT_BILLED    personne n'a réussi à la chiffrer — anomalie
     *
     * `NOT_BILLABLE` est une décision de paramétrage ; `NOT_BILLED` est un
     * oubli qui laisse le patient repartir sans que la clinique ait compté
     * ce qu'elle a consommé. Les confondre reviendrait à masquer le second
     * derrière le premier.
     *
     * @return array<string, mixed>
     */
    private function lineBilling(CareConsumableRequestLine $line): array
    {
        $item = $line->billableItem;

        if (! $item) {
            $billable = (bool) $line->medicine?->catalogItem?->billable;

            return [
                'state' => $billable ? 'NOT_BILLED' : 'NOT_BILLABLE',
                'label' => $billable ? 'Non facturé' : 'Non facturable',
                'needs_attention' => $billable,
                'reason' => $billable
                    ? 'Aucun tarif de vente actif, ou contexte financier du passage encore en attente au moment de la déclaration. À régulariser par la Réception avant la sortie administrative du passage.'
                    : 'Ce produit est configuré comme non facturable dans le catalogue.',
                'amount' => null,
                'currency' => null,
                'invoice' => null,
            ];
        }

        $invoice = $item->invoiceLine?->invoice;

        return [
            'state' => match ($item->status) {
                BillableItemStatus::Invoiced => 'INVOICED',
                BillableItemStatus::Cancelled => 'CANCELLED',
                BillableItemStatus::Pending => 'PENDING',
            },
            'label' => match ($item->status) {
                BillableItemStatus::Invoiced => 'Sur facture',
                BillableItemStatus::Cancelled => 'Prestation annulée',
                BillableItemStatus::Pending => 'À porter sur une facture',
            },
            // Une prestation en attente n'est pas une anomalie : elle rejoint
            // la facture du passage, ou la file « Sorties & règlements » de la
            // Réception qui la signale avant de clore le compte (ADR-090).
            'needs_attention' => false,
            'reason' => null,
            'amount' => (float) $item->total_amount,
            'currency' => $item->currency,
            'invoice' => $invoice ? [
                'uuid' => $invoice->uuid,
                'number' => $invoice->invoice_number,
                'status' => $invoice->status->value,
                'status_label' => $invoice->status->label(),
                'settled' => $invoice->status->isSettled(),
                'total_amount' => (float) $invoice->total_amount,
                'paid_amount' => (float) $invoice->paid_amount,
                'balance_amount' => (float) $invoice->balance_amount,
            ] : null,
        ];
    }

    /**
     * Le cumul d'une demande : ce que le patient doit pour ce matériel, et
     * la ou les factures qui le portent.
     *
     * Une prestation annulée est exclue du total — la compter ferait dire à
     * l'écran que le patient doit une somme que sa facture ne porte pas.
     *
     * @return array<string, mixed>
     */
    private function requestBilling(CareConsumableRequest $request): array
    {
        $lines = $request->lines->map(fn (CareConsumableRequestLine $line) => $this->lineBilling($line));

        return [
            'total_amount' => (float) $lines
                ->reject(fn (array $billing) => $billing['state'] === 'CANCELLED')
                ->sum(fn (array $billing) => $billing['amount'] ?? 0),
            'currency' => $lines->firstWhere('currency', '!=', null)['currency'] ?? 'MGA',
            'unbilled_lines' => $lines->where('state', 'NOT_BILLED')->count(),
            // Dédoublonnées par numéro : les lignes d'une même demande
            // rejoignent normalement la facture du passage, et l'afficher une
            // fois par ligne laisserait croire à plusieurs factures.
            'invoices' => $lines
                ->pluck('invoice')
                ->filter()
                ->unique('number')
                ->values()
                ->all(),
        ];
    }
}
