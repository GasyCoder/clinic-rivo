<?php

namespace App\Services\Care;

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
 * No price is ever exposed here: a clinician never sees or enters an amount
 * (ADR-036), the charge is resolved server-side and collected by
 * Réception/Caisse.
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
            ->with($this->relations())
            ->oldest('requested_at')
            ->get();

        $recent = CareConsumableRequest::query()
            ->whereIn('status', [
                CareConsumableRequestStatus::Served->value,
                CareConsumableRequestStatus::Cancelled->value,
            ])
            ->with($this->relations())
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
            ],
            'requests' => $this->present($open->concat($recent))->all(),
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
    private function relations(): array
    {
        return [
            'lines.allocations.medicineLot:id,uuid,lot_number,expires_at',
            'requester:id,name',
            'server:id,name',
            'canceller:id,name',
            'episode:id,uuid,episode_number,patient_id',
            'episode.patient:id,uuid,patient_number,first_name,last_name',
        ];
    }

    /**
     * @param  Collection<int, CareConsumableRequest>  $requests
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $requests): Collection
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
            'lines' => $request->lines->map(fn (CareConsumableRequestLine $line) => [
                'uuid' => $line->uuid,
                'name' => $line->medicine_name,
                'code' => $line->medicine_code,
                'unit' => $line->unit,
                'quantity_requested' => $line->quantity_requested,
                'quantity_served' => $line->quantity_served,
                'remaining_quantity' => $line->remainingQuantity(),
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
}
