<?php

namespace App\Services\Pharmacy;

use App\Enums\MedicineStockReservationStatus;
use App\Models\Medicine;
use App\Models\MedicineLot;
use Carbon\CarbonImmutable;

class MedicineStockOverviewService
{
    /** @return array{summary: array<string, int>, medicines: array<int, array<string, mixed>>} */
    public function overview(): array
    {
        $today = CarbonImmutable::today();
        $expiryLimit = $today->addDays(90);

        $medicines = Medicine::query()
            ->with([
                'catalogItem:id,uuid,code,name,unit',
                'lots' => fn ($query) => $query
                    ->where('active', true)
                    ->withSum([
                        'reservations as reserved_quantity' => fn ($reservationQuery) => $reservationQuery
                            ->where('status', MedicineStockReservationStatus::Reserved->value),
                    ], 'quantity')
                    ->orderBy('expires_at')
                    ->orderBy('id'),
            ])
            ->orderBy('generic_name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Medicine $medicine) => $medicine->catalogItem !== null)
            ->map(function (Medicine $medicine) use ($today, $expiryLimit): array {
                $lots = $medicine->lots->map(function (MedicineLot $lot) use ($today, $expiryLimit): array {
                    $reserved = (int) ($lot->reserved_quantity ?? 0);
                    $expired = $lot->expires_at->lt($today);
                    $available = $expired ? 0 : max(0, $lot->quantity_on_hand - $reserved);
                    $status = $expired
                        ? 'EXPIRED'
                        : ($lot->expires_at->lte($expiryLimit) ? 'EXPIRING_SOON' : 'AVAILABLE');

                    return [
                        'uuid' => $lot->uuid,
                        'lot_number' => $lot->lot_number,
                        'received_at' => $lot->received_at?->toDateString(),
                        'expires_at' => $lot->expires_at->toDateString(),
                        'quantity_on_hand' => $lot->quantity_on_hand,
                        'reserved_quantity' => $reserved,
                        'available_quantity' => $available,
                        'status' => $status,
                    ];
                })->values();

                $usableLots = $lots->where('status', '!=', 'EXPIRED');
                $physical = (int) $lots->sum('quantity_on_hand');
                $reserved = (int) $usableLots->sum('reserved_quantity');
                $available = (int) $usableLots->sum('available_quantity');
                $hasExpiring = $usableLots->contains('status', 'EXPIRING_SOON');

                return [
                    'uuid' => $medicine->uuid,
                    'catalog_uuid' => $medicine->catalogItem->uuid,
                    'code' => $medicine->catalogItem->code,
                    'name' => $medicine->catalogItem->name,
                    'generic_name' => $medicine->generic_name,
                    'form' => $medicine->form->value,
                    'form_label' => $medicine->form->label(),
                    'strength' => $medicine->strength,
                    'unit' => $medicine->catalogItem->unit,
                    'active' => $medicine->active,
                    'quantity_on_hand' => $physical,
                    'reserved_quantity' => $reserved,
                    'available_quantity' => $available,
                    'expired_quantity' => (int) $lots->where('status', 'EXPIRED')->sum('quantity_on_hand'),
                    'nearest_expiration' => $usableLots->where('available_quantity', '>', 0)->min('expires_at'),
                    'status' => ! $medicine->active
                        ? 'INACTIVE'
                        : ($available === 0 ? 'OUT_OF_STOCK' : ($hasExpiring ? 'EXPIRING_SOON' : 'AVAILABLE')),
                    'lots' => $lots->all(),
                ];
            })
            ->sortBy(fn (array $medicine) => str($medicine['name'])->lower()->toString())
            ->values();

        return [
            'summary' => [
                'medicines' => $medicines->count(),
                'quantity_on_hand' => (int) $medicines->sum('quantity_on_hand'),
                'reserved_quantity' => (int) $medicines->sum('reserved_quantity'),
                'available_quantity' => (int) $medicines->sum('available_quantity'),
                'out_of_stock' => $medicines->where('status', 'OUT_OF_STOCK')->count(),
                'expiring_soon' => $medicines->where('status', 'EXPIRING_SOON')->count(),
                'expired_lots' => $medicines->sum(fn (array $medicine) => collect($medicine['lots'])->where('status', 'EXPIRED')->count()),
            ],
            'medicines' => $medicines->all(),
        ];
    }
}
