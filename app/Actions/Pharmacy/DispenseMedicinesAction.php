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

            // ADR-162 (amende l'ADR-049 pour ce seul cas) — la délivrance au
            // service d'un patient hospitalisé exige une facture préparée, pas
            // réglée : le traitement ne peut pas attendre la Caisse.
            $settled = $dispense->invoice
                && ($dispense->isWardDispense()
                    ? $dispense->invoice->status !== InvoiceStatus::Cancelled
                    : in_array($dispense->invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Covered], true));

            if (! $dispense->status->canDispense() || ! $settled) {
                throw ValidationException::withMessages([
                    'dispense' => $dispense->isWardDispense()
                        ? 'La délivrance au service exige une facture préparée.'
                        : 'La délivrance exige une facture intégralement réglée ou prise en charge.',
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
                $this->replaceUnusableReservations($dispense, $line, $actor);
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
    /**
     * Un lot réservé à l'ordonnance (ou à la vente) peut périmer — ou être désactivé — avant
     * que le patient ait réglé. Sa réservation est alors reportée sur les lots encore
     * valides du même médicament, en FEFO, sans jamais entamer ce qui est réservé pour
     * quelqu'un d'autre : c'est la « réallocation » que la délivrance exigeait sans
     * qu'aucun écran ne permette de la faire. L'ancienne réservation est libérée avec son
     * motif, jamais effacée. Si les lots valides ne couvrent pas tout, rien ne bouge et le
     * refus dit combien il manque.
     */
    private function replaceUnusableReservations(PharmacyDispense $dispense, PharmacyDispenseLine $line, User $actor): void
    {
        $today = CarbonImmutable::today();
        $lots = MedicineLot::query()
            ->withReservedQuantity()
            ->where('medicine_id', $line->medicine_id)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $stale = $this->reservations($dispense, $line)
            ->filter(fn ($reservation) => ! ($lots->get($reservation->medicine_lot_id)?->isUsableOn($today) ?? false))
            ->values();

        if ($stale->isEmpty()) {
            return;
        }

        $needed = (int) $stale->sum('remaining_quantity');
        $usable = $lots->filter(fn (MedicineLot $lot) => $lot->isUsableOn($today) && $lot->availableQuantity() > 0);
        $available = (int) $usable->sum(fn (MedicineLot $lot) => $lot->availableQuantity());
        $staleLots = $stale->map(fn ($reservation) => $lots->get($reservation->medicine_lot_id)?->lot_number ?? '?')->unique()->implode(', ');

        if ($available < $needed) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'Le lot %s de %s est périmé ou retiré depuis la réservation : %d à remplacer, %d disponible(s) dans des lots valides. Recevez du stock ou délivrez moins.',
                    $staleLots, $line->medicine_name, $needed, $available,
                ),
            ]);
        }

        foreach ($stale as $reservation) {
            $toPlace = (int) $reservation->remaining_quantity;

            foreach ($usable as $lot) {
                if ($toPlace === 0) {
                    break;
                }

                $take = min($toPlace, $lot->availableQuantity());
                if ($take <= 0) {
                    continue;
                }

                // Une ligne n'a qu'une réservation par lot : celle qu'elle a déjà sur ce lot
                // grandit d'autant (quantité et reste ; délivré = quantité − reste ne change pas).
                $owner = $reservation instanceof MedicineStockReservation ? 'prescription_line_id' : 'pharmacy_dispense_line_id';
                $existing = $reservation::query()
                    ->where($owner, $reservation->getAttribute($owner))
                    ->where('medicine_lot_id', $lot->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->forceFill([
                        'quantity' => (int) $existing->quantity + $take,
                        'remaining_quantity' => (int) $existing->remaining_quantity + $take,
                        'status' => MedicineStockReservationStatus::Reserved,
                        'released_at' => null,
                        'released_by' => null,
                        'release_reason' => null,
                    ])->save();
                } else {
                    $copy = $reservation->replicate(['uuid', 'released_at', 'released_by', 'release_reason', 'dispensed_at', 'dispensed_by']);
                    $copy->forceFill([
                        'medicine_lot_id' => $lot->getKey(),
                        'quantity' => $take,
                        'remaining_quantity' => $take,
                        'status' => MedicineStockReservationStatus::Reserved,
                        'reserved_at' => now(),
                        'reserved_by' => $actor->getKey(),
                    ])->save();
                }

                // La disponibilité du lot tient compte de ce qui vient d'y être réservé.
                $lot->setAttribute('prescription_reserved_quantity', (int) ($lot->prescription_reserved_quantity ?? 0) + $take);
                $toPlace -= $take;
            }

            $reservation->forceFill([
                'status' => MedicineStockReservationStatus::Released,
                'remaining_quantity' => 0,
                'released_at' => now(),
                'released_by' => $actor->getKey(),
                'release_reason' => sprintf('Lot %s périmé ou retiré avant la délivrance : réservation reportée sur un lot valide (FEFO).', $lots->get($reservation->medicine_lot_id)?->lot_number ?? '?'),
            ])->save();
        }
    }

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
