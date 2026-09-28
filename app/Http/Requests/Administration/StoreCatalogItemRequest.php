<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ImagingModality;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use App\Support\Catalog\CatalogTariffReason;
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

        // ADR-044 — « Motif automatique » du tarif initial, écrit par le serveur.
        if ($this->boolean('tariff_reason_auto') && filled($this->input('tariff_amount'))) {
            $this->merge(['tariff_reason' => CatalogTariffReason::INITIAL]);
        }
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
            'staff_coverage_policy' => ['sometimes', new Enum(StaffCoveragePolicy::class)],
            // ADR-106 — ECG ou échographie : réglée ici, jamais déduite du code.
            'imaging_modality' => ['sometimes', 'nullable', new Enum(ImagingModality::class)],
            'reception_selectable' => ['sometimes', 'boolean'],
            'reception_routing_mode' => [
                'nullable',
                Rule::requiredIf($this->boolean('reception_selectable')),
                new Enum(ReceptionRoutingMode::class),
            ],
            'care_requires_allergy_check' => ['sometimes', 'boolean'],
            'care_recommends_vitals' => ['sometimes', 'boolean'],
            'clinician_orderable' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tariff_amount' => ['nullable', 'required_if:billable,true', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'mutual_tariff_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'tariff_reason_auto' => ['sometimes', 'boolean'],
            'tariff_reason' => ['nullable', 'required_if:billable,true', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Le code accepte uniquement les lettres majuscules, chiffres, points, tirets et underscores.',
            'code.unique' => 'Ce code existe déjà, y compris dans les éléments archivés.',
            'tariff_amount.required_if' => 'Le tarif initial est obligatoire pour un élément facturable.',
            'mutual_tariff_amount.gt' => 'Le tarif mutuelle doit être supérieur à zéro.',
            'tariff_reason.required_if' => 'Le motif du tarif initial est obligatoire.',
            'reception_routing_mode.required' => 'Choisissez le parcours clinique de cette prestation à la Réception.',
        ];
    }
}
