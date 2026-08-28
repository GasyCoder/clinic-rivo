<?php

namespace App\Actions\Pharmacy;

use App\Enums\InvoiceStatus;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Enums\PharmacyDispenseType;
use App\Enums\PharmacyStockMovementType;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\PharmacyDispense;
use App\Models\PharmacyDispenseAllocation;
use App\Models\PharmacyDispenseEvent;
use App\Models\PharmacyDispenseLine;
use App\Models\PharmacyDispenseLotReservation;
use App\Models\PharmacyStockMovement;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Services\Pharmacy\MedicineStockAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispenseMedicinesAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly MedicineStockAlertService $alerts,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(PharmacyDispense $dispense, array $data, User $actor): PharmacyDispenseEvent
    {
        return DB::transaction(function () use ($dispense, $data, $actor): PharmacyDispenseEvent {
            $dispense = PharmacyDispense::query()
                ->with('invoice')
                ->lockForUpdate()
                ->findOrFail($dispense->getKey());

            if (! $dispense->status->canDispense()
                || ! $dispense->invoice
                || ! in_array($dispense->invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Covered], true)) {
                throw ValidationException::withMessages([
                    'dispense' => 'La délivrance exige une facture intégralement réglée ou prise en charge.',
                ]);
            }

            $submitted = collect($data['lines'])->keyBy('uuid');
            $lines = PharmacyDispenseLine::query()
                ->where('pharmacy_dispense_id', $dispense->getKey())
                ->whereIn('uuid', $submitted->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== $submitted->count()) {
                throw ValidationException::withMessages(['lines' => 'Une ligne ne correspond pas à cette demande.']);
            }

            foreach ($lines as $index => $line) {
                $quantity = (int) $submitted[$line->uuid]['quantity'];

                if ($quantity < 1 || $quantity > $line->remainingQuantity()) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => 'La quantité à délivrer dépasse le reliquat de cette ligne.',
                    ]);
                }
            }

            $event = PharmacyDispenseEvent::query()->create([
                'pharmacy_dispense_id' => $dispense->getKey(),
                'delivery_number' => $this->numbers->pharmacyDelivery(),
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'dispensed_at' => now(),
                'dispensed_by' => $actor->getKey(),
            ]);
            $movementSequence = 0;

            foreach ($lines as $line) {
                $requested = (int) $submitted[$line->uuid]['quantity'];
                $reservations = $this->reservations($dispense, $line);
                $reservable = (int) $reservations->sum('remaining_quantity');

                if ($requested > $reservable) {
                    throw ValidationException::withMessages([
                        'lines' => "Les réservations restantes de {$line->medicine_name} sont insuffisantes.",
                    ]);
                }

                $remaining = $requested;

                foreach ($reservations as $reservation) {
                    if ($remaining === 0) {
                        break;
                    }

                    $lot = MedicineLot::query()->lockForUpdate()->findOrFail($reservation->medicine_lot_id);

                    if (! $lot->active || $lot->expires_at->lt(CarbonImmutable::today())) {
                        throw ValidationException::withMessages([
                            'lines' => "Le lot {$lot->lot_number} de {$line->medicine_name} est inactif ou périmé. Réallouez le stock avant la délivrance.",
                        ]);
                    }

                    $allocated = min($remaining, (int) $reservation->remaining_quantity);

                    if ($lot->quantity_on_hand < $allocated) {
                        throw ValidationException::withMessages([
                            'lines' => "Le stock physique du lot {$lot->lot_number} est devenu insuffisant.",
                        ]);
                    }

                    $balanceAfter = $lot->quantity_on_hand - $allocated;
                    $lot->update(['quantity_on_hand' => $balanceAfter, 'updated_by' => $actor->getKey()]);
                    $movement = PharmacyStockMovement::query()->create([
                        'medicine_lot_id' => $lot->getKey(),
                        'type' => PharmacyStockMovementType::Dispensing,
                        'quantity_delta' => -$allocated,
                        'balance_after' => $balanceAfter,
                        'source_key' => sprintf('dispense:%s:%d', $event->uuid, ++$movementSequence),
                        'origin' => sprintf('Stock Pharmacie — %s', config('rivo.site.name') ?: config('rivo.site.code')),
                        'destination' => $dispense->type === PharmacyDispenseType::Internal
                            ? sprintf('Patient — %s', $dispense->patient?->patient_number ?? $dispense->patient_id)
                            : ($dispense->customer_name ?: 'Client comptoir'),
                        'reason' => "Délivrance {$event->delivery_number}",
                        'occurred_at' => $event->dispensed_at,
                        'performed_by' => $actor->getKey(),
                    ]);
                    PharmacyDispenseAllocation::query()->create([
                        'pharmacy_dispense_event_id' => $event->getKey(),
                        'pharmacy_dispense_line_id' => $line->getKey(),
                        'medicine_lot_id' => $lot->getKey(),
                        'pharmacy_stock_movement_id' => $movement->getKey(),
                        'quantity' => $allocated,
                    ]);

                    $newRemaining = $reservation->remaining_quantity - $allocated;
                    $reservation->update([
                        'remaining_quantity' => $newRemaining,
                        'status' => $newRemaining === 0
                            ? MedicineStockReservationStatus::Dispensed
                            : MedicineStockReservationStatus::Reserved,
                        'dispensed_at' => $newRemaining === 0 ? $event->dispensed_at : null,
                        'dispensed_by' => $newRemaining === 0 ? $actor->getKey() : null,
                    ]);
                    $remaining -= $allocated;
                }

                $line->update(['quantity_dispensed' => $line->quantity_dispensed + $requested]);
                $this->alerts->synchronize($line->medicine);
            }

            $hasRemaining = PharmacyDispenseLine::query()
                ->where('pharmacy_dispense_id', $dispense->getKey())
                ->whereColumn('quantity_dispensed', '<', 'quantity_requested')
                ->exists();
            $dispense->update([
                'status' => $hasRemaining
                    ? PharmacyDispenseStatus::PartiallyDispensed
                    : PharmacyDispenseStatus::Dispensed,
                'completed_at' => $hasRemaining ? null : now(),
            ]);

            $this->auditor->record(
                'pharmacy.dispense',
                entity: $dispense,
                newValues: ['delivery_uuid' => $event->uuid, 'delivery_number' => $event->delivery_number],
                module: 'pharmacy',
                actor: $actor,
            );

            return $event->load('allocations.medicineLot', 'dispense.invoice');
        });
    }

    /** @return Collection<int, MedicineStockReservation|PharmacyDispenseLotReservation> */
    private function reservations(PharmacyDispense $dispense, PharmacyDispenseLine $line): Collection
    {
        $query = $dispense->type === PharmacyDispenseType::Internal
            ? MedicineStockReservation::query()->where('prescription_line_id', $line->prescription_line_id)
            : PharmacyDispenseLotReservation::query()->where('pharmacy_dispense_line_id', $line->getKey());

        return $query
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->where('remaining_quantity', '>', 0)
            ->with('medicineLot:id,expires_at')
            ->orderBy(
                MedicineLot::select('expires_at')->whereColumn('medicine_lots.id', $query->getModel()->qualifyColumn('medicine_lot_id')),
            )
            ->orderBy('medicine_lot_id')
            ->lockForUpdate()
            ->get();
    }
}
