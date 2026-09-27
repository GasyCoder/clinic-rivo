<?php

namespace App\Actions\Pharmacy;

use App\Enums\CareConsumableRequestStatus;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyStockMovementType;
use App\Models\CareConsumableAllocation;
use App\Models\CareConsumableRequest;
use App\Models\CareConsumableRequestLine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\PharmacyDispenseLotReservation;
use App\Models\PharmacyStockMovement;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Pharmacy\MedicineStockAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-072 — Pharmacy records the stock exit of consumables already used at
 * Soins. Unlike DispenseMedicinesAction, this deliberately does NOT require
 * a settled invoice: the compress is already on the wound, so keeping it on
 * the shelf until the patient pays would make the stock knowingly wrong.
 * The patient's charge lives on its own BillableItem, collected by
 * Réception/Caisse like any other (ADR-012 unchanged).
 *
 * Allocation is FEFO and never eats into quantities already reserved for a
 * prescription or a counter sale (ADR-049).
 */
class ServeCareConsumablesAction
{
    public function __construct(
        private readonly MedicineStockAlertService $alerts,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        CareConsumableRequest $request,
        array $data,
        User $actor,
    ): CareConsumableRequest {
        return DB::transaction(function () use ($request, $data, $actor): CareConsumableRequest {
            $request = CareConsumableRequest::query()
                ->with('episode.patient:id,patient_number')
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            if (! $request->status->canBeServed()) {
                throw ValidationException::withMessages([
                    'request' => 'Cette demande de consommables ne peut plus être servie.',
                ]);
            }

            $submitted = collect($data['lines'])->keyBy('uuid');
            $lines = CareConsumableRequestLine::query()
                ->where('care_consumable_request_id', $request->getKey())
                ->whereIn('uuid', $submitted->keys())
                ->with('medicine')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== $submitted->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Une ligne ne correspond pas à cette demande.',
                ]);
            }

            foreach ($lines as $index => $line) {
                $quantity = (int) $submitted[$line->uuid]['quantity'];

                if ($quantity < 1 || $quantity > $line->remainingQuantity()) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => 'La quantité à sortir dépasse le reliquat de cette ligne.',
                    ]);
                }
            }

            $servedAt = now();
            $destination = sprintf(
                '%s — patient %s',
                $request->sourceLabel(),
                $request->episode?->patient?->patient_number ?? $request->episode?->episode_number ?? 'interne',
            );

            foreach ($lines as $line) {
                $remaining = (int) $submitted[$line->uuid]['quantity'];
                // The movement source_key is unique: a second partial exit
                // on the same line must continue the sequence, never
                // restart at 1 and collide with the first one.
                $movementSequence = $line->allocations()->count();

                foreach ($this->usableLots($line) as $lot) {
                    if ($remaining === 0) {
                        break;
                    }

                    $available = $lot->quantity_on_hand - $this->reservedQuantity($lot);

                    if ($available <= 0) {
                        continue;
                    }

                    $allocated = min($remaining, $available);
                    $balanceAfter = $lot->quantity_on_hand - $allocated;
                    $lot->update(['quantity_on_hand' => $balanceAfter, 'updated_by' => $actor->getKey()]);

                    $movement = PharmacyStockMovement::query()->create([
                        'medicine_lot_id' => $lot->getKey(),
                        'type' => PharmacyStockMovementType::Dispensing,
                        'quantity_delta' => -$allocated,
                        'balance_after' => $balanceAfter,
                        'source_key' => sprintf('care_consumable:%s:%d', $line->uuid, ++$movementSequence),
                        'origin' => sprintf('Stock Pharmacie — %s', config('rivo.site.name') ?: config('rivo.site.code')),
                        'destination' => $destination,
                        'reason' => "Consommables {$request->sourceLabel()} {$request->request_number}",
                        'occurred_at' => $servedAt,
                        'performed_by' => $actor->getKey(),
                    ]);

                    CareConsumableAllocation::query()->create([
                        'care_consumable_request_line_id' => $line->getKey(),
                        'medicine_lot_id' => $lot->getKey(),
                        'pharmacy_stock_movement_id' => $movement->getKey(),
                        'quantity' => $allocated,
                        'served_at' => $servedAt,
                        'served_by' => $actor->getKey(),
                    ]);

                    $remaining -= $allocated;
                }

                if ($remaining > 0) {
                    // The consumable was physically used but the recorded
                    // stock cannot cover it: that is an inventory
                    // discrepancy, never a silent negative balance. The
                    // pharmacist adjusts the inventory first (stock.adjust),
                    // then serves the request again.
                    throw ValidationException::withMessages([
                        'lines' => "Le stock disponible de {$line->medicine_name} est insuffisant ({$remaining} manquant(e)s). Ajustez l’inventaire de ce produit avant de servir la demande.",
                    ]);
                }

                $line->update([
                    'quantity_served' => $line->quantity_served + (int) $submitted[$line->uuid]['quantity'],
                ]);
                $this->alerts->synchronize($line->medicine);
            }

            $hasRemaining = CareConsumableRequestLine::query()
                ->where('care_consumable_request_id', $request->getKey())
                ->whereColumn('quantity_served', '<', 'quantity_requested')
                ->exists();

            $request->update([
                'status' => $hasRemaining
                    ? CareConsumableRequestStatus::PartiallyServed
                    : CareConsumableRequestStatus::Served,
                'served_at' => $hasRemaining ? null : $servedAt,
                'served_by' => $hasRemaining ? null : $actor->getKey(),
            ]);

            $this->auditor->record(
                'pharmacy.care_consumables.serve',
                entity: $request,
                newValues: [
                    'request_number' => $request->request_number,
                    'status' => $request->status->value,
                ],
                module: 'pharmacy',
                actor: $actor,
            );

            return $request->fresh(['lines.allocations.medicineLot', 'server:id,name']);
        });
    }

    /** @return Collection<int, MedicineLot> */
    private function usableLots(CareConsumableRequestLine $line)
    {
        return MedicineLot::query()
            ->where('medicine_id', $line->medicine_id)
            ->where('active', true)
            ->whereDate('expires_at', '>=', CarbonImmutable::today())
            ->where('quantity_on_hand', '>', 0)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Quantities already promised to a prescription or a counter sale. */
    private function reservedQuantity(MedicineLot $lot): int
    {
        $prescription = (int) MedicineStockReservation::query()
            ->where('medicine_lot_id', $lot->getKey())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->sum('remaining_quantity');
        $counter = (int) PharmacyDispenseLotReservation::query()
            ->where('medicine_lot_id', $lot->getKey())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->sum('remaining_quantity');

        return $prescription + $counter;
    }
}
