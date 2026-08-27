<?php

namespace App\Services\Pharmacy;

use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyStockAlertType;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\PharmacyDispenseLotReservation;
use App\Models\PharmacyStockAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MedicineStockAlertService
{
    public function availableQuantity(Medicine $medicine): int
    {
        $lotIds = MedicineLot::query()
            ->where('medicine_id', $medicine->getKey())
            ->where('active', true)
            ->whereDate('expires_at', '>=', CarbonImmutable::today()->toDateString())
            ->pluck('id');

        if ($lotIds->isEmpty()) {
            return 0;
        }

        $physical = (int) MedicineLot::query()->whereIn('id', $lotIds)->sum('quantity_on_hand');
        $medicalReserved = (int) MedicineStockReservation::query()
            ->whereIn('medicine_lot_id', $lotIds)
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->sum('remaining_quantity');
        $counterReserved = (int) PharmacyDispenseLotReservation::query()
            ->whereIn('medicine_lot_id', $lotIds)
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->sum('remaining_quantity');

        return max(0, $physical - $medicalReserved - $counterReserved);
    }

    public function synchronize(Medicine $medicine): ?PharmacyStockAlert
    {
        return DB::transaction(function () use ($medicine): ?PharmacyStockAlert {
            $medicine = Medicine::query()->lockForUpdate()->findOrFail($medicine->getKey());
            $available = $this->availableQuantity($medicine);
            $type = $available === 0
                ? PharmacyStockAlertType::OutOfStock
                : ($medicine->minimum_stock > 0 && $available <= $medicine->minimum_stock
                    ? PharmacyStockAlertType::LowStock
                    : null);
            $open = PharmacyStockAlert::query()
                ->where('medicine_id', $medicine->getKey())
                ->where('active_key', 'OPEN')
                ->lockForUpdate()
                ->first();

            if ($type === null) {
                if ($open) {
                    $open->update([
                        'status' => 'RESOLVED',
                        'active_key' => null,
                        'available_quantity' => $available,
                        'threshold' => $medicine->minimum_stock,
                        'resolved_at' => now(),
                    ]);
                }

                return null;
            }

            if ($open) {
                $open->update([
                    'type' => $type,
                    'available_quantity' => $available,
                    'threshold' => $medicine->minimum_stock,
                    'resolved_at' => null,
                ]);

                return $open->refresh();
            }

            return PharmacyStockAlert::query()->create([
                'medicine_id' => $medicine->getKey(),
                'type' => $type,
                'status' => 'OPEN',
                'active_key' => 'OPEN',
                'available_quantity' => $available,
                'threshold' => $medicine->minimum_stock,
                'triggered_at' => now(),
            ]);
        });
    }
}
