<?php

namespace App\Actions\Medicine;

use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPrescriptionAction
{
    public function __construct(private readonly MedicineStockAlertService $alerts) {}

    public function execute(Prescription $prescription, string $reason, User $actor): Prescription
    {
        return DB::transaction(function () use ($prescription, $reason, $actor): Prescription {
            $prescription = Prescription::query()
                ->with('pharmacyDispense')
                ->lockForUpdate()
                ->findOrFail($prescription->getKey());

            if ($prescription->pharmacyDispense?->invoice_id !== null) {
                throw ValidationException::withMessages([
                    'prescription' => 'Cette ordonnance a déjà été transmise à la Caisse et ne peut plus être annulée depuis Médecine.',
                ]);
            }

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
                    'remaining_quantity' => 0,
                    'released_at' => now(),
                    'released_by' => $actor->getKey(),
                    'release_reason' => $reason,
                ]);
            }

            if ($prescription->pharmacyDispense) {
                $prescription->pharmacyDispense->update([
                    'status' => PharmacyDispenseStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->getKey(),
                    'cancellation_reason' => $reason,
                ]);
            }

            $prescription->cancel($reason);

            MedicineLot::query()
                ->whereIn('id', $reservationLotIds)
                ->pluck('medicine_id')
                ->unique()
                ->each(fn (int $medicineId) => $this->alerts->synchronize(
                    Medicine::query()->findOrFail($medicineId),
                ));

            return $prescription;
        });
    }
}
