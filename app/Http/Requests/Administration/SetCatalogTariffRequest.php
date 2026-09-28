<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogTariffCategory;
use App\Support\Catalog\CatalogTariffReason;
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

        // ADR-044 — « Motif automatique » : le serveur écrit le motif, jamais le
        // navigateur ; il dit la grille et le nouveau montant.
        $category = CatalogTariffCategory::tryFrom((string) $this->input('tariff_category'));
        $amount = $this->input('tariff_amount');

        if ($this->boolean('reason_auto') && $category && is_numeric($amount)) {
            $this->merge(['reason' => CatalogTariffReason::change($category, (string) $amount)]);
        }
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
            'reason_auto' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
