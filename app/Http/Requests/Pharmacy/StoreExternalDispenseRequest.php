<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class StoreExternalDispenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pharmacy.counter_sales.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'external_prescriber' => ['nullable', 'string', 'max:255'],
            'print_after_create' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.medicine_uuid' => ['required', 'uuid', 'distinct', 'exists:medicines,uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
