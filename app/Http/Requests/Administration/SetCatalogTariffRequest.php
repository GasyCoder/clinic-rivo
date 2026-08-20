<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class SetCatalogTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('catalog.tariffs.create') ?? false)
            || ($this->user()?->can('catalog.tariffs.update') ?? false);
    }

    public function rules(): array
    {
        return [
            'tariff_amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
