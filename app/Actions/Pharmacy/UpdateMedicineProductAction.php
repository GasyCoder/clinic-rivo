<?php

namespace App\Actions\Pharmacy;

use App\Actions\Catalog\SetCatalogTariffAction;
use App\Enums\CatalogTariffCategory;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\MedicineStockAlertService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — corrects a clinic medicine record. The code never changes: imports
 * and stock files designate the medicine by it. A new sale price never
 * overwrites the previous one: SetCatalogTariffAction closes it and opens a
 * dated version, so sales already billed keep their price (ADR-024).
 */
class UpdateMedicineProductAction
{
    public function __construct(
        private readonly SetCatalogTariffAction $setTariff,
        private readonly MedicineStockAlertService $alerts,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Medicine $medicine, array $data, CatalogActor $actor): Medicine
    {
        if ($actor->cannot('medicines.update') || $actor->cannot('catalog.items.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier ce médicament.');
        }

        return DB::transaction(function () use ($medicine, $data, $actor): Medicine {
            $medicine = Medicine::query()->with('catalogItem.currentStandardTariff')->lockForUpdate()->findOrFail($medicine->id);
            $item = $medicine->catalogItem;

            $item->update([
                'name' => trim($data['name']),
                'unit' => trim($data['unit']),
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'updated_by' => $actor->localUserId() ?? $item->updated_by,
                ...$actor->externalAttribution('updated'),
            ]);

            $medicine->update([
                'medicine_category_id' => filled($data['medicine_category_uuid'] ?? null)
                    ? MedicineCategory::query()->where('uuid', $data['medicine_category_uuid'])->value('id')
                    : null,
                'generic_name' => trim($data['generic_name']),
                'form' => $data['form'],
                'strength' => trim($data['strength']),
                'manufacturer' => filled($data['manufacturer'] ?? null) ? trim($data['manufacturer']) : null,
                'barcode' => filled($data['barcode'] ?? null) ? trim($data['barcode']) : null,
                'minimum_stock' => (int) $data['minimum_stock'],
                'prescription_required' => (bool) $data['prescription_required'],
                'updated_by' => $actor->localUserId() ?? $medicine->updated_by,
            ]);

            if (array_key_exists('supplier_uuids', $data)) {
                // A supplier with a current price stays linked: the price is the
                // stronger fact, and removing the link would make them diverge.
                $withOffer = $medicine->supplierOffers()->where('active_key', 'CURRENT')->pluck('medicine_supplier_id');
                $chosen = MedicineSupplier::query()->whereIn('uuid', $data['supplier_uuids'] ?? [])->pluck('id');
                $medicine->suppliers()->sync($chosen->merge($withOffer)->unique()->values()->all());
            }

            $this->updatePriceIfChanged($medicine, $data, $actor);
            $this->alerts->synchronize($medicine);

            return $medicine->fresh(['catalogItem.currentStandardTariff', 'category', 'suppliers']);
        });
    }

    /** @param array<string, mixed> $data */
    private function updatePriceIfChanged(Medicine $medicine, array $data, CatalogActor $actor): void
    {
        if (! filled($data['sale_price'] ?? null)) {
            return;
        }

        $current = $medicine->catalogItem->currentStandardTariff?->amount;

        if ($current !== null && Money::toMinor($current) === Money::toMinor($data['sale_price'])) {
            return;
        }

        if (! filled($data['tariff_reason'] ?? null) || mb_strlen(trim((string) $data['tariff_reason'])) < 3) {
            throw ValidationException::withMessages([
                'tariff_reason' => 'Indiquez pourquoi le prix de vente change : l’ancien prix reste dans l’historique.',
            ]);
        }

        $this->setTariff->execute(
            $medicine->catalogItem,
            CatalogTariffCategory::Standard,
            (string) $data['sale_price'],
            trim((string) $data['tariff_reason']),
            $actor,
        );
    }
}
