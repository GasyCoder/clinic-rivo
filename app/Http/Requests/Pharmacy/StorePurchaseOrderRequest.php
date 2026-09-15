<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase_orders.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_delivery_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.medicine_uuid' => ['required', 'uuid', 'exists:medicines,uuid'],
            'lines.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $uuids = collect($this->input('lines', []))->pluck('medicine_uuid')->filter();

            if ($uuids->count() !== $uuids->unique()->count()) {
                $validator->errors()->add('lines', 'Un même médicament ne peut apparaître qu’une seule fois dans la commande.');
            }
        });
    }
}
