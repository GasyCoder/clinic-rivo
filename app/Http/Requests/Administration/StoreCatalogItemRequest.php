<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreCatalogItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'unit' => trim((string) $this->input('unit')),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('catalog.items.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('catalog_items', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(CatalogItemType::class)],
            'module' => ['required', new Enum(CatalogModule::class)],
            'unit' => ['required', 'string', 'max:50'],
            'billable' => ['required', 'boolean'],
            'stockable' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tariff_amount' => ['nullable', 'required_if:billable,true', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'tariff_reason' => ['nullable', 'required_if:billable,true', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Le code accepte uniquement les lettres majuscules, chiffres, points, tirets et underscores.',
            'code.unique' => 'Ce code existe déjà, y compris dans les éléments archivés.',
            'tariff_amount.required_if' => 'Le tarif initial est obligatoire pour un élément facturable.',
            'tariff_reason.required_if' => 'Le motif du tarif initial est obligatoire.',
        ];
    }
}
