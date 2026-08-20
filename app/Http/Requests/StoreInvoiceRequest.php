<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'billable_item_uuids' => ['nullable', 'array', 'max:100', 'required_without:catalog_lines'],
            'billable_item_uuids.*' => ['required', 'uuid', 'distinct'],
            'catalog_lines' => ['nullable', 'array', 'max:50', 'required_without:billable_item_uuids'],
            'catalog_lines.*.catalog_item_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('billable', true)),
            ],
            'catalog_lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
        ];
    }
}
