<?php

namespace App\Services\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineStockReservationStatus;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\PharmacyDispenseLotReservation;
use App\Models\PrescriptionLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MedicineStockService
{
    public function __construct(private readonly MedicineStockAlertService $alerts) {}

    /**
     * Read-only aggregate intended for Medicine. The doctor never receives
     * stock mutation controls or direct access to individual movements.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function availableCatalog(): Collection
    {
        $today = CarbonImmutable::today();

        return Medicine::query()
            ->where('active', true)
            ->whereHas('catalogItem', fn ($query) => $query
                ->where('type', CatalogItemType::Medicine->value)
                ->where('module', CatalogModule::Pharmacy->value)
                ->where('stockable', true))
            ->with([
                'catalogItem:id,uuid,code,name,unit',
                'lots' => fn ($query) => $query
                    ->where('active', true)
                    ->withSum([
                        'reservations as prescription_reserved_quantity' => fn ($reservationQuery) => $reservationQuery
                            ->where('status', MedicineStockReservationStatus::Reserved->value),
                    ], 'remaining_quantity')
                    ->withSum([
                        'counterReservations as counter_reserved_quantity' => fn ($reservationQuery) => $reservationQuery
                            ->where('status', MedicineStockReservationStatus::Reserved->value),
                    ], 'remaining_quantity')
                    ->orderBy('expires_at')
                    ->orderBy('id'),
            ])
            ->orderBy('generic_name')
            ->orderBy('id')
            ->get()
            ->map(function (Medicine $medicine) use ($today): array {
                $usableLots = $medicine->lots
                    ->filter(fn (MedicineLot $lot) => $lot->expires_at->gte($today));
                $availableQuantity = $usableLots->sum(
                    fn (MedicineLot $lot) => max(0, $lot->quantity_on_hand - $this->reservedQuantity($lot)),
                );
                $nearestExpiration = $usableLots
                    ->filter(fn (MedicineLot $lot) => $lot->quantity_on_hand > $this->reservedQuantity($lot))
                    ->min('expires_at');

                return [
                    'uuid' => $medicine->catalogItem->uuid,
                    'code' => $medicine->catalogItem->code,
                    'name' => $medicine->catalogItem->name,
                    'generic_name' => $medicine->generic_name,
                    'form' => $medicine->form->value,
                    'form_label' => $medicine->form->label(),
                    'strength' => $medicine->strength,
                    'unit' => $medicine->catalogItem->unit,
                    'available_quantity' => $availableQuantity,
                    'available' => $availableQuantity > 0,
                    'nearest_expiration' => $nearestExpiration?->toDateString(),
                    'expiring_soon' => $nearestExpiration?->lte($today->addDays(90)) ?? false,
                    'expired_lot_count' => $medicine->lots
                        ->filter(fn (MedicineLot $lot) => $lot->expires_at->lt($today) && $lot->quantity_on_hand > 0)
                        ->count(),
                ];
            })
            ->sortBy(fn (array $medicine) => str($medicine['name'])->lower()->toString())
            ->values();
    }

    /**
     * Resolve and lock every medicine before lot allocation. Sorting the
     * locks prevents two prescriptions containing several medicines from
     * deadlocking when their form order differs.
     *
     * @param  array<int, string>  $catalogUuids
     * @return Collection<string, Medicine>
     */
    public function lockPrescribableMedicines(array $catalogUuids): Collection
    {
        return Medicine::query()
            ->where('active', true)
            ->whereHas('catalogItem', fn ($query) => $query
                ->whereIn('uuid', $catalogUuids)
                ->where('type', CatalogItemType::Medicine->value)
                ->where('module', CatalogModule::Pharmacy->value)
                ->where('stockable', true))
            ->with('catalogItem:id,uuid,code,name,unit')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Medicine $medicine) => $medicine->catalogItem->uuid);
    }

    /**
     * Reserve available lots in FEFO order. A prescription reserves stock;
     * only the future Pharmacy dispensing action will decrement on-hand.
     *
     * @return array{available_before: int, earliest_expiration: ?string}
     */
    public function reserve(
        Medicine $medicine,
        PrescriptionLine $line,
        int $requestedQuantity,
        User $actor,
        string $errorField,
    ): array {
        $lots = MedicineLot::query()
            ->where('medicine_id', $medicine->getKey())
            ->where('active', true)
            ->whereDate('expires_at', '>=', CarbonImmutable::today()->toDateString())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $reservedByLot = MedicineStockReservation::query()
            ->whereIn('medicine_lot_id', $lots->modelKeys())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->selectRaw('medicine_lot_id, SUM(remaining_quantity) as reserved_quantity')
            ->groupBy('medicine_lot_id')
            ->pluck('reserved_quantity', 'medicine_lot_id');
        $counterReservedByLot = PharmacyDispenseLotReservation::query()
            ->whereIn('medicine_lot_id', $lots->modelKeys())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->selectRaw('medicine_lot_id, SUM(remaining_quantity) as reserved_quantity')
            ->groupBy('medicine_lot_id')
            ->pluck('reserved_quantity', 'medicine_lot_id');

        $availableByLot = $lots->mapWithKeys(fn (MedicineLot $lot) => [
            $lot->getKey() => max(
                0,
                $lot->quantity_on_hand
                    - (int) ($reservedByLot[$lot->getKey()] ?? 0)
                    - (int) ($counterReservedByLot[$lot->getKey()] ?? 0),
            ),
        ]);
        $availableBefore = (int) $availableByLot->sum();

        if ($requestedQuantity > $availableBefore) {
            throw ValidationException::withMessages([
                $errorField => sprintf(
                    'Stock insuffisant pour %s : %d %s disponible(s), %d demandé(s). L’ordonnance n’a pas été créée.',
                    $medicine->catalogItem->name,
                    $availableBefore,
                    $medicine->catalogItem->unit,
                    $requestedQuantity,
                ),
            ]);
        }

        $remaining = $requestedQuantity;
        $earliestExpiration = null;

        foreach ($lots as $lot) {
            $available = (int) $availableByLot[$lot->getKey()];

            if ($available === 0) {
                continue;
            }

            $allocated = min($available, $remaining);
            $earliestExpiration ??= $lot->expires_at->toDateString();

            MedicineStockReservation::query()->create([
                'prescription_line_id' => $line->getKey(),
                'medicine_lot_id' => $lot->getKey(),
                'quantity' => $allocated,
                'remaining_quantity' => $allocated,
                'status' => MedicineStockReservationStatus::Reserved,
                'reserved_at' => now(),
                'reserved_by' => $actor->getKey(),
            ]);

            $remaining -= $allocated;

            if ($remaining === 0) {
                break;
            }
        }

        $this->alerts->synchronize($medicine);

        return [
            'available_before' => $availableBefore,
            'earliest_expiration' => $earliestExpiration,
        ];
    }

    /**
     * Rebuild an active line reservation without deleting its trace. Existing
     * rows are adjusted/released/reactivated and every change remains audited.
     *
     * @return array{available_before: int, earliest_expiration: ?string}
     */
    public function reallocate(
        Medicine $medicine,
        PrescriptionLine $line,
        int $requestedQuantity,
        User $actor,
        string $errorField,
    ): array {
        $lots = MedicineLot::query()
            ->where('medicine_id', $medicine->getKey())
            ->where('active', true)
            ->whereDate('expires_at', '>=', CarbonImmutable::today()->toDateString())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $reservations = MedicineStockReservation::query()
            ->where('prescription_line_id', $line->getKey())
            ->orderBy('medicine_lot_id')
            ->lockForUpdate()
            ->get();

        if ($reservations->contains(fn (MedicineStockReservation $reservation) => $reservation->status === MedicineStockReservationStatus::Dispensed)) {
            throw ValidationException::withMessages([
                $errorField => 'Cette ligne a déjà été délivrée par la Pharmacie et ne peut plus être modifiée.',
            ]);
        }

        $otherReservedByLot = MedicineStockReservation::query()
            ->whereIn('medicine_lot_id', $lots->modelKeys())
            ->where('prescription_line_id', '!=', $line->getKey())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->selectRaw('medicine_lot_id, SUM(remaining_quantity) as reserved_quantity')
            ->groupBy('medicine_lot_id')
            ->pluck('reserved_quantity', 'medicine_lot_id');
        $counterReservedByLot = PharmacyDispenseLotReservation::query()
            ->whereIn('medicine_lot_id', $lots->modelKeys())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->selectRaw('medicine_lot_id, SUM(remaining_quantity) as reserved_quantity')
            ->groupBy('medicine_lot_id')
            ->pluck('reserved_quantity', 'medicine_lot_id');
        $availableByLot = $lots->mapWithKeys(fn (MedicineLot $lot) => [
            $lot->getKey() => max(
                0,
                $lot->quantity_on_hand
                    - (int) ($otherReservedByLot[$lot->getKey()] ?? 0)
                    - (int) ($counterReservedByLot[$lot->getKey()] ?? 0),
            ),
        ]);
        $availableBefore = (int) $availableByLot->sum();

        if ($requestedQuantity > $availableBefore) {
            throw ValidationException::withMessages([
                $errorField => sprintf(
                    'Stock insuffisant pour %s : %d %s disponible(s), %d demandé(s). La modification n’a pas été enregistrée.',
                    $medicine->catalogItem->name,
                    $availableBefore,
                    $medicine->catalogItem->unit,
                    $requestedQuantity,
                ),
            ]);
        }

        $desiredByLot = collect();
        $remaining = $requestedQuantity;

        foreach ($lots as $lot) {
            $allocated = min((int) $availableByLot[$lot->getKey()], $remaining);

            if ($allocated > 0) {
                $desiredByLot->put($lot->getKey(), $allocated);
                $remaining -= $allocated;
            }

            if ($remaining === 0) {
                break;
            }
        }

        $reservationByLot = $reservations->keyBy('medicine_lot_id');

        foreach ($reservations->where('status', MedicineStockReservationStatus::Reserved) as $reservation) {
            if ($desiredByLot->has($reservation->medicine_lot_id)) {
                continue;
            }

            $reservation->update([
                'status' => MedicineStockReservationStatus::Released,
                'remaining_quantity' => 0,
                'released_at' => now(),
                'released_by' => $actor->getKey(),
                'release_reason' => 'Réallocation après modification de l’ordonnance.',
            ]);
        }

        foreach ($desiredByLot as $lotId => $quantity) {
            $reservation = $reservationByLot->get($lotId);

            if ($reservation) {
                $reservation->update([
                    'quantity' => $quantity,
                    'remaining_quantity' => $quantity,
                    'status' => MedicineStockReservationStatus::Reserved,
                    'reserved_at' => now(),
                    'reserved_by' => $actor->getKey(),
                    'released_at' => null,
                    'released_by' => null,
                    'release_reason' => null,
                ]);

                continue;
            }

            MedicineStockReservation::query()->create([
                'prescription_line_id' => $line->getKey(),
                'medicine_lot_id' => $lotId,
                'quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'status' => MedicineStockReservationStatus::Reserved,
                'reserved_at' => now(),
                'reserved_by' => $actor->getKey(),
            ]);
        }

        $this->alerts->synchronize($medicine);

        return [
            'available_before' => $availableBefore,
            'earliest_expiration' => $lots
                ->first(fn (MedicineLot $lot) => $desiredByLot->has($lot->getKey()))
                ?->expires_at
                ?->toDateString(),
        ];
    }

    private function reservedQuantity(MedicineLot $lot): int
    {
        return (int) ($lot->prescription_reserved_quantity ?? 0)
            + (int) ($lot->counter_reserved_quantity ?? 0);
    }
}
