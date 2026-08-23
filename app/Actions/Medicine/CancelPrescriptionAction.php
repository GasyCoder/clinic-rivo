<?php

namespace App\Actions\Medicine;

use App\Enums\MedicineStockReservationStatus;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelPrescriptionAction
{
    public function execute(Prescription $prescription, string $reason, User $actor): Prescription
    {
        return DB::transaction(function () use ($prescription, $reason, $actor): Prescription {
            $prescription = Prescription::query()
                ->lockForUpdate()
                ->findOrFail($prescription->getKey());

            $reservationLotIds = MedicineStockReservation::query()
                ->whereHas('prescriptionLine', fn ($query) => $query
                    ->where('prescription_id', $prescription->getKey()))
                ->where('status', MedicineStockReservationStatus::Reserved->value)
                ->orderBy('medicine_lot_id')
                ->pluck('medicine_lot_id');

            MedicineLot::query()
                ->whereIn('id', $reservationLotIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $reservations = MedicineStockReservation::query()
                ->whereHas('prescriptionLine', fn ($query) => $query
                    ->where('prescription_id', $prescription->getKey()))
                ->where('status', MedicineStockReservationStatus::Reserved->value)
                ->orderBy('medicine_lot_id')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->update([
                    'status' => MedicineStockReservationStatus::Released->value,
                    'released_at' => now(),
                    'released_by' => $actor->getKey(),
                    'release_reason' => $reason,
                ]);
            }

            $prescription->cancel($reason);

            return $prescription;
        });
    }
}
