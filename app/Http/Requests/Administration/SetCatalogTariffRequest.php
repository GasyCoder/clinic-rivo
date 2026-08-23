<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogTariffCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SetCatalogTariffRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'tariff_category' => $this->input(
                'tariff_category',
                CatalogTariffCategory::Standard->value,
            ),
        ]);
    }

    public function authorize(): bool
    {
        return ($this->user()?->can('catalog.tariffs.create') ?? false)
            || ($this->user()?->can('catalog.tariffs.update') ?? false);
    }

    public function rules(): array
    {
        return [
            'tariff_category' => ['required', new Enum(CatalogTariffCategory::class)],
            'tariff_amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
