<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'episode_uuid' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }
}
