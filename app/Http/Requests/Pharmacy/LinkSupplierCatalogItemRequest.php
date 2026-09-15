<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class LinkSupplierCatalogItemRequest extends FormRequest
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
            'change_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
