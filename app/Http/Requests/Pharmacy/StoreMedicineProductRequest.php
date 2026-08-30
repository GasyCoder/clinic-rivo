<?php

namespace App\Http\Requests\Pharmacy;

use App\Enums\MedicineForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicineProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('medicines.create') === true
            && $this->user()?->can('catalog.items.create') === true
            && $this->user()?->can('catalog.tariffs.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('catalog_items', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['required', 'string', 'max:255'],
            'form' => ['required', Rule::enum(MedicineForm::class)],
            'strength' => ['required', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:50'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('medicines', 'barcode')],
            'medicine_category_uuid' => ['nullable', 'uuid', 'exists:medicine_categories,uuid'],
            'supplier_uuids' => ['sometimes', 'array', 'max:100'],
            'supplier_uuids.*' => ['uuid', 'distinct', 'exists:medicine_suppliers,uuid'],
            'minimum_stock' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'prescription_required' => ['required', 'boolean'],
            'sale_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'tariff_reason' => ['required', 'string', 'min:3', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
