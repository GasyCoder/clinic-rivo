<?php

namespace App\Actions\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineStockReservationStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Enums\PharmacyDispenseType;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineStockReservation;
use App\Models\PharmacyDispense;
use App\Models\PharmacyDispenseLotReservation;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateExternalDispenseAction
{
    public function __construct(
        private readonly PrepareDispenseInvoiceAction $prepareInvoice,
        private readonly MedicineStockAlertService $alerts,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): PharmacyDispense
    {
        return DB::transaction(function () use ($data, $actor): PharmacyDispense {
            $requested = collect($data['lines'])->keyBy('medicine_uuid');
            $medicines = Medicine::query()
                ->whereIn('uuid', $requested->keys())
                ->where('active', true)
                ->whereHas('catalogItem', fn ($query) => $query
                    ->where('type', CatalogItemType::Medicine->value)
                    ->where('module', CatalogModule::Pharmacy->value)
                    ->where('stockable', true)
                    ->where('billable', true))
                ->with('catalogItem:id,uuid,code,name,unit')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            if ($medicines->count() !== $requested->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Un médicament sélectionné est inactif, non facturable ou absent du référentiel Pharmacie.',
                ]);
            }

            if (blank($data['external_prescription_reference'] ?? null)
                && $medicines->contains(fn (Medicine $medicine) => $medicine->prescription_required)) {
                throw ValidationException::withMessages([
                    'external_prescription_reference' => 'Une référence d’ordonnance est obligatoire pour au moins un médicament sélectionné.',
                ]);
            }

            $dispense = PharmacyDispense::query()->create([
                'type' => PharmacyDispenseType::External,
                'customer_name' => filled($data['customer_name'] ?? null) ? trim($data['customer_name']) : null,
                'customer_phone' => filled($data['customer_phone'] ?? null) ? trim($data['customer_phone']) : null,
                'external_prescription_reference' => filled($data['external_prescription_reference'] ?? null)
                    ? trim($data['external_prescription_reference'])
                    : null,
                'external_prescriber' => filled($data['external_prescriber'] ?? null)
                    ? trim($data['external_prescriber'])
                    : null,
                'status' => PharmacyDispenseStatus::AwaitingInvoice,
                'requested_at' => now(),
                'requested_by' => $actor->getKey(),
            ]);

            foreach ($data['lines'] as $index => $submitted) {
                $medicine = $medicines->get($submitted['medicine_uuid']);
                $quantity = (int) $submitted['quantity'];
                $line = $dispense->lines()->create([
                    'medicine_id' => $medicine->getKey(),
                    'medicine_name' => $medicine->catalogItem->name,
                    'medicine_code' => $medicine->catalogItem->code,
                    'unit' => $medicine->catalogItem->unit,
                    'quantity_requested' => $quantity,
                    'quantity_dispensed' => 0,
                ]);

                $this->reserve($medicine, $line->getKey(), $quantity, $actor, "lines.{$index}.quantity");
                $this->alerts->synchronize($medicine);
            }

            return $this->prepareInvoice->execute($dispense, $actor);
        });
    }

    private function reserve(Medicine $medicine, int $lineId, int $requested, User $actor, string $errorField): void
    {
        $lots = MedicineLot::query()
            ->where('medicine_id', $medicine->getKey())
            ->where('active', true)
            ->whereDate('expires_at', '>=', CarbonImmutable::today()->toDateString())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $medical = MedicineStockReservation::query()
            ->whereIn('medicine_lot_id', $lots->modelKeys())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->selectRaw('medicine_lot_id, SUM(remaining_quantity) as reserved_quantity')
            ->groupBy('medicine_lot_id')
            ->pluck('reserved_quantity', 'medicine_lot_id');
        $counter = PharmacyDispenseLotReservation::query()
            ->whereIn('medicine_lot_id', $lots->modelKeys())
            ->where('status', MedicineStockReservationStatus::Reserved->value)
            ->selectRaw('medicine_lot_id, SUM(remaining_quantity) as reserved_quantity')
            ->groupBy('medicine_lot_id')
            ->pluck('reserved_quantity', 'medicine_lot_id');
        $available = $lots->mapWithKeys(fn (MedicineLot $lot) => [
            $lot->getKey() => max(0, $lot->quantity_on_hand
                - (int) ($medical[$lot->getKey()] ?? 0)
                - (int) ($counter[$lot->getKey()] ?? 0)),
        ]);

        if ($requested > $available->sum()) {
            throw ValidationException::withMessages([
                $errorField => sprintf('Stock insuffisant pour %s : %d disponible(s), %d demandé(s).', $medicine->catalogItem->name, $available->sum(), $requested),
            ]);
        }

        $remaining = $requested;

        foreach ($lots as $lot) {
            $allocated = min((int) $available[$lot->getKey()], $remaining);

            if ($allocated > 0) {
                PharmacyDispenseLotReservation::query()->create([
                    'pharmacy_dispense_line_id' => $lineId,
                    'medicine_lot_id' => $lot->getKey(),
                    'quantity' => $allocated,
                    'remaining_quantity' => $allocated,
                    'status' => MedicineStockReservationStatus::Reserved,
                    'reserved_at' => now(),
                    'reserved_by' => $actor->getKey(),
                ]);
                $remaining -= $allocated;
            }

            if ($remaining === 0) {
                break;
            }
        }
    }
}
