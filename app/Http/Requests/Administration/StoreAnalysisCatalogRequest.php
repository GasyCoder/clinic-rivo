<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnalysisCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('analysis_catalog.create');
    }

    public function rules(): array
    {
        return $this->catalogRules();
    }

    /** @return array<string, mixed> */
    protected function catalogRules(?AnalysisCatalog $ignore = null): array
    {
        return [
            'catalog_item_uuid' => [
                'required',
                'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Laboratory->value)
                    ->whereNull('deleted_at')),
            ],
            'parent_uuid' => ['nullable', 'uuid', Rule::exists('analysis_catalogs', 'uuid')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:80', Rule::unique('analysis_catalogs', 'code')->ignore($ignore?->getKey())],
            'level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'designation' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'reference_general' => ['nullable', 'string', 'max:255'],
            'reference_male' => ['nullable', 'string', 'max:255'],
            'reference_female' => ['nullable', 'string', 'max:255'],
            'reference_child_male' => ['nullable', 'string', 'max:255'],
            'reference_child_female' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:60'],
            'predefined_values' => ['nullable', 'array', 'max:30'],
            'predefined_values.*' => ['required', 'string', 'max:100', 'distinct'],
            'display_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'catalog_item_uuid.exists' => 'Choisissez une prestation active appartenant au Laboratoire.',
            'parent_uuid.exists' => 'L’analyse parente est introuvable ou inactive.',
            'code.unique' => 'Ce code d’analyse existe déjà.',
        ];
    }
}
