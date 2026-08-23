<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogTariffCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ArchiveCatalogTariffRequest extends FormRequest
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
        return $this->user()?->can('catalog.tariffs.archive') ?? false;
    }

    public function rules(): array
    {
        return [
            'tariff_category' => ['required', new Enum(CatalogTariffCategory::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
