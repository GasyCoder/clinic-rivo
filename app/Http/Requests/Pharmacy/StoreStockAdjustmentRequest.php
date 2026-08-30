<?php

namespace App\Http\Requests\Pharmacy;

use App\Enums\PharmacyStockAdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stock.adjust') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lot_uuid' => ['required', 'uuid', 'exists:medicine_lots,uuid'],
            'type' => ['required', Rule::enum(PharmacyStockAdjustmentType::class)],
            'quantity' => [
                Rule::requiredIf($this->input('type') !== PharmacyStockAdjustmentType::Inventory->value),
                'nullable', 'integer', 'min:1', 'max:1000000000',
            ],
            'counted_quantity' => [
                Rule::requiredIf($this->input('type') === PharmacyStockAdjustmentType::Inventory->value),
                'nullable', 'integer', 'min:0', 'max:1000000000',
            ],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
