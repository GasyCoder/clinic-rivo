<?php

namespace App\Actions\Pharmacy;

use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyStockAdjustmentType;
use App\Enums\PharmacyStockMovementType;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\PharmacyDispenseLotReservation;
use App\Models\PharmacyStockMovement;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdjustMedicineStockAction
{
    public function __construct(private readonly MedicineStockAlertService $alerts) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): PharmacyStockMovement
    {
        return DB::transaction(function () use ($data, $actor): PharmacyStockMovement {
            $lot = MedicineLot::query()
                ->with('medicine')
                ->where('uuid', $data['lot_uuid'])
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('adjust', $lot);

            $type = PharmacyStockAdjustmentType::from($data['type']);
            $balanceAfter = $type === PharmacyStockAdjustmentType::Inventory
                ? (int) $data['counted_quantity']
                : $lot->quantity_on_hand - (int) $data['quantity'];

            if ($balanceAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'La quantité retirée dépasse le stock physique du lot.',
                ]);
            }

            $reserved = (int) MedicineStockReservation::query()
                ->where('medicine_lot_id', $lot->getKey())
                ->where('status', MedicineStockReservationStatus::Reserved->value)
                ->sum('remaining_quantity')
                + (int) PharmacyDispenseLotReservation::query()
                    ->where('medicine_lot_id', $lot->getKey())
                    ->where('status', MedicineStockReservationStatus::Reserved->value)
                    ->sum('remaining_quantity');

            if ($balanceAfter < $reserved) {
                throw ValidationException::withMessages([
                    'quantity' => "L’ajustement laisserait {$balanceAfter} unité(s), alors que {$reserved} sont réservée(s).",
                ]);
            }

            $delta = $balanceAfter - $lot->quantity_on_hand;

            if ($delta === 0) {
                throw ValidationException::withMessages([
                    'counted_quantity' => 'Le comptage correspond déjà au stock enregistré : aucun mouvement à créer.',
                ]);
            }

            $lot->update(['quantity_on_hand' => $balanceAfter, 'updated_by' => $actor->getKey()]);
            $movement = PharmacyStockMovement::query()->create([
                'medicine_lot_id' => $lot->getKey(),
                'type' => PharmacyStockMovementType::Adjustment,
                'quantity_delta' => $delta,
                'balance_after' => $balanceAfter,
                'origin' => sprintf('Stock Pharmacie — %s', config('rivo.site.name') ?: config('rivo.site.code')),
                'destination' => $type === PharmacyStockAdjustmentType::Inventory
                    ? 'Inventaire physique'
                    : $type->label(),
                'reason' => sprintf('%s — %s', $type->label(), trim($data['reason'])),
                'occurred_at' => now(),
                'performed_by' => $actor->getKey(),
            ]);

            $this->alerts->synchronize($lot->medicine);

            return $movement;
        });
    }
}
