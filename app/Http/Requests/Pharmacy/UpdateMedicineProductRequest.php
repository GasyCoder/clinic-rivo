<?php

namespace App\Http\Requests\Pharmacy;

use App\Enums\MedicineForm;
use App\Models\Medicine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicineProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medicines.update') === true
            && $this->user()?->can('catalog.items.update') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor($this->route('medicine'));
    }

    /**
     * Shared with the site API used by the portal. The code is absent on
     * purpose: it identifies the medicine and never changes.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(Medicine $medicine): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['required', 'string', 'max:255'],
            'form' => ['required', Rule::enum(MedicineForm::class)],
            'strength' => ['required', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:50'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('medicines', 'barcode')->ignore($medicine->id)],
            'medicine_category_uuid' => ['nullable', 'uuid', Rule::exists('medicine_categories', 'uuid')->whereNull('deleted_at')],
            'supplier_uuids' => ['sometimes', 'array', 'max:100'],
            'supplier_uuids.*' => ['uuid', 'distinct', 'exists:medicine_suppliers,uuid'],
            'minimum_stock' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'prescription_required' => ['required', 'boolean'],
            'sale_price' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'tariff_reason' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
