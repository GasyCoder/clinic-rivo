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
            'billable_item_uuids' => ['nullable', 'array', 'max:100', 'required_without:lines'],
            'billable_item_uuids.*' => ['required', 'uuid', 'distinct'],
            'lines' => ['nullable', 'array', 'max:50', 'required_without:billable_item_uuids'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }
}
