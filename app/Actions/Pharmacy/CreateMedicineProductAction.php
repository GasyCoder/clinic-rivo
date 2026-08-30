<?php

namespace App\Actions\Pharmacy;

use App\Actions\Catalog\CreateCatalogItemAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Facades\DB;

class CreateMedicineProductAction
{
    public function __construct(private readonly CreateCatalogItemAction $createCatalogItem) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): Medicine
    {
        return DB::transaction(function () use ($data, $actor): Medicine {
            $catalog = $this->createCatalogItem->execute([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => CatalogItemType::Medicine->value,
                'module' => CatalogModule::Pharmacy->value,
                'unit' => $data['unit'],
                'billable' => true,
                'stockable' => true,
                'description' => $data['description'] ?? null,
                'tariff_amount' => $data['sale_price'],
                'tariff_reason' => $data['tariff_reason'],
            ], CatalogActor::fromUser($actor));
            $categoryId = filled($data['medicine_category_uuid'] ?? null)
                ? MedicineCategory::query()->where('uuid', $data['medicine_category_uuid'])->value('id')
                : null;
            $medicine = Medicine::query()->create([
                'catalog_item_id' => $catalog->getKey(),
                'medicine_category_id' => $categoryId,
                'generic_name' => trim($data['generic_name']),
                'form' => $data['form'],
                'strength' => trim($data['strength']),
                'manufacturer' => filled($data['manufacturer'] ?? null) ? trim($data['manufacturer']) : null,
                'barcode' => filled($data['barcode'] ?? null) ? trim($data['barcode']) : null,
                'minimum_stock' => (int) $data['minimum_stock'],
                'prescription_required' => (bool) $data['prescription_required'],
                'active' => true,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $supplierIds = MedicineSupplier::query()
                ->whereIn('uuid', $data['supplier_uuids'] ?? [])
                ->pluck('id');
            $medicine->suppliers()->sync($supplierIds);

            return $medicine->load('catalogItem.currentStandardTariff', 'category', 'suppliers');
        });
    }
}
