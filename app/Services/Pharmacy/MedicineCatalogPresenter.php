<?php

namespace App\Services\Pharmacy;

use App\Enums\MedicineForm;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Support\Money;

/**
 * ADR-098 — a medicine record and the medicine families, in the shape their
 * edit screens need. Used by the clinic and by the site API read by the
 * portal, so both screens describe the same data the same way.
 */
class MedicineCatalogPresenter
{
    /** @return array<string, mixed> */
    public function medicine(Medicine $medicine): array
    {
        $medicine->loadMissing(['catalogItem.currentStandardTariff', 'category' => fn ($query) => $query->withTrashed(), 'suppliers']);

        return [
            'uuid' => $medicine->uuid,
            'code' => $medicine->catalogItem->code,
            'name' => $medicine->catalogItem->name,
            'unit' => $medicine->catalogItem->unit,
            'description' => $medicine->catalogItem->description,
            'generic_name' => $medicine->generic_name,
            'form' => $medicine->form->value,
            'strength' => $medicine->strength,
            'manufacturer' => $medicine->manufacturer,
            'barcode' => $medicine->barcode,
            'medicine_category_uuid' => $medicine->category?->uuid,
            'supplier_uuids' => $medicine->suppliers->pluck('uuid')->values(),
            'minimum_stock' => $medicine->minimum_stock,
            'prescription_required' => $medicine->prescription_required,
            'sale_price' => $medicine->catalogItem->currentStandardTariff
                ? Money::normalize((string) $medicine->catalogItem->currentStandardTariff->amount)
                : null,
            'active' => $medicine->active,
            'deactivation_reason' => $medicine->active ? null : $medicine->delete_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function formOptions(bool $withSuppliers): array
    {
        return [
            'categories' => MedicineCategory::query()->orderBy('name')->get(['uuid', 'code', 'name']),
            'suppliers' => $withSuppliers ? MedicineSupplier::query()->orderBy('name')->get(['uuid', 'code', 'name']) : [],
            'medicineForms' => collect(MedicineForm::cases())->map(fn (MedicineForm $form) => [
                'value' => $form->value,
                'label' => $form->label(),
            ])->values(),
        ];
    }

    /** @return array<int, array<string, mixed>> Archived families included, to be restorable. */
    public function categories(): array
    {
        return MedicineCategory::query()
            ->withTrashed()
            ->withCount([
                'medicines',
                'medicines as active_medicines_count' => fn ($query) => $query->where('active', true),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (MedicineCategory $category) => [
                'uuid' => $category->uuid,
                'code' => $category->code,
                'name' => $category->name,
                'description' => $category->description,
                'medicines_count' => $category->medicines_count,
                'active_medicines_count' => $category->active_medicines_count,
                'archived' => $category->trashed(),
                'delete_reason' => $category->delete_reason,
            ])
            ->values()
            ->all();
    }
}
