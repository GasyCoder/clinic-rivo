<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class StoreMedicineSupplierOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permission is checked inside SetMedicineSupplierOfferAction (create vs update).
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'medicine_uuid' => ['required', 'uuid', 'exists:medicines,uuid'],
            'quoted_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'supplier_reference' => ['nullable', 'string', 'max:120'],
            'change_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
