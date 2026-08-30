<?php

namespace App\Actions\Pharmacy;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\PharmacyStockEntryOperation;
use App\Enums\PharmacyStockMovementType;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineSupplier;
use App\Models\PharmacyStockMovement;
use App\Models\User;
use App\Services\Pharmacy\MedicineStockAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordStockEntryAction
{
    public function __construct(private readonly MedicineStockAlertService $alerts) {}

    /**
     * @param array{
     *   medicine_uuid: string,
     *   operation: string,
     *   lot_number: string,
     *   received_at?: ?string,
     *   expires_at: string,
     *   quantity: int,
     *   origin: string,
     *   destination: string,
     *   reason: string
     * } $data
     */
    public function execute(array $data, User $actor): PharmacyStockMovement
    {
        return DB::transaction(function () use ($data, $actor): PharmacyStockMovement {
            $medicine = Medicine::query()
                ->where('uuid', $data['medicine_uuid'])
                ->where('active', true)
                ->whereHas('catalogItem', fn ($query) => $query
                    ->where('type', CatalogItemType::Medicine->value)
                    ->where('module', CatalogModule::Pharmacy->value)
                    ->where('stockable', true))
                ->lockForUpdate()
                ->first();

            if (! $medicine) {
                throw ValidationException::withMessages([
                    'medicine_uuid' => 'Ce médicament n’est pas actif et stockable dans le référentiel Pharmacie.',
                ]);
            }

            $operation = PharmacyStockEntryOperation::from($data['operation']);
            $supplier = filled($data['supplier_uuid'] ?? null)
                ? MedicineSupplier::query()->where('uuid', $data['supplier_uuid'])->lockForUpdate()->firstOrFail()
                : null;
            $lot = MedicineLot::query()
                ->where('medicine_id', $medicine->getKey())
                ->where('lot_number', $data['lot_number'])
                ->lockForUpdate()
                ->first();

            if ($operation === PharmacyStockEntryOperation::InitialStock && $lot) {
                throw ValidationException::withMessages([
                    'lot_number' => 'Ce lot existe déjà : utilisez ENTREE, jamais STOCK_INITIAL.',
                ]);
            }

            if ($lot && ! $lot->active) {
                throw ValidationException::withMessages([
                    'lot_number' => 'Ce lot est inactif et ne peut pas recevoir une entrée.',
                ]);
            }

            if ($lot) {
                Gate::forUser($actor)->authorize('receive', $lot);
            }

            if ($lot && $lot->expires_at->toDateString() !== $data['expires_at']) {
                throw ValidationException::withMessages([
                    'expires_at' => sprintf(
                        'La péremption de ce lot est déjà enregistrée au %s.',
                        $lot->expires_at->format('d/m/Y'),
                    ),
                ]);
            }

            if ($lot && $supplier && $lot->medicine_supplier_id && $lot->medicine_supplier_id !== $supplier->getKey()) {
                throw ValidationException::withMessages([
                    'supplier_uuid' => 'Ce lot est déjà rattaché à un autre fournisseur.',
                ]);
            }

            if (! $lot) {
                Gate::forUser($actor)->authorize('create', MedicineLot::class);

                $lot = MedicineLot::query()->create([
                    'medicine_id' => $medicine->getKey(),
                    'medicine_supplier_id' => $supplier?->getKey(),
                    'lot_number' => $data['lot_number'],
                    'received_at' => $data['received_at'] ?? null,
                    'expires_at' => $data['expires_at'],
                    'quantity_on_hand' => 0,
                    'active' => true,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);
            }

            $balanceAfter = $lot->quantity_on_hand + (int) $data['quantity'];
            $lot->update([
                'received_at' => $lot->received_at ?? ($data['received_at'] ?? null),
                'medicine_supplier_id' => $lot->medicine_supplier_id ?? $supplier?->getKey(),
                'quantity_on_hand' => $balanceAfter,
                'updated_by' => $actor->getKey(),
            ]);

            if ($supplier) {
                $medicine->suppliers()->syncWithoutDetaching([$supplier->getKey()]);
            }

            $movement = PharmacyStockMovement::query()->create([
                'medicine_lot_id' => $lot->getKey(),
                'medicine_supplier_id' => $supplier?->getKey(),
                'type' => $operation === PharmacyStockEntryOperation::InitialStock
                    ? PharmacyStockMovementType::Opening
                    : PharmacyStockMovementType::Entry,
                'quantity_delta' => (int) $data['quantity'],
                'balance_after' => $balanceAfter,
                'unit_purchase_price' => $data['unit_purchase_price'] ?? null,
                'origin' => $data['origin'],
                'destination' => $data['destination'],
                'reason' => $data['reason'],
                'occurred_at' => now(),
                'performed_by' => $actor->getKey(),
            ]);

            $this->alerts->synchronize($medicine);

            return $movement;
        });
    }
}
