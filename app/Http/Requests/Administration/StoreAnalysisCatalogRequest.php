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
            'exam_category' => ['nullable', 'string', 'max:100'],
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
            'is_bold' => ['sometimes', 'boolean'],
            // Inline sub-analyses edited alongside their group (one level —
            // a deeper nested group still goes through the normal parent
            // picker). Uniqueness of children.*.code is left to
            // AnalysisCatalogManager's own DB-level guard: a dynamic
            // per-index "ignore self" unique rule isn't expressible here.
            'children' => ['sometimes', 'array', 'max:60'],
            'children.*.uuid' => ['nullable', 'uuid'],
            'children.*.code' => ['required', 'string', 'max:80'],
            'children.*.level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'children.*.designation' => ['required', 'string', 'max:255'],
            'children.*.description' => ['nullable', 'string', 'max:2000'],
            'children.*.exam_category' => ['nullable', 'string', 'max:100'],
            'children.*.result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'children.*.reference_general' => ['nullable', 'string', 'max:255'],
            'children.*.reference_male' => ['nullable', 'string', 'max:255'],
            'children.*.reference_female' => ['nullable', 'string', 'max:255'],
            'children.*.reference_child_male' => ['nullable', 'string', 'max:255'],
            'children.*.reference_child_female' => ['nullable', 'string', 'max:255'],
            'children.*.unit' => ['nullable', 'string', 'max:60'],
            'children.*.predefined_values' => ['nullable', 'array', 'max:30'],
            'children.*.predefined_values.*' => ['required', 'string', 'max:100', 'distinct'],
            'children.*.display_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'children.*.is_active' => ['sometimes', 'boolean'],
            'children.*.is_bold' => ['sometimes', 'boolean'],
            // A sub-analysis can itself be a group with its own sub-analyses
            // (grandchildren) — the inline editor goes exactly one level
            // deeper than children.*; a third level still goes through the
            // normal parent picker as a separate entry.
            'children.*.children' => ['sometimes', 'array', 'max:60'],
            'children.*.children.*.uuid' => ['nullable', 'uuid'],
            'children.*.children.*.code' => ['required', 'string', 'max:80'],
            'children.*.children.*.level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'children.*.children.*.designation' => ['required', 'string', 'max:255'],
            'children.*.children.*.description' => ['nullable', 'string', 'max:2000'],
            'children.*.children.*.exam_category' => ['nullable', 'string', 'max:100'],
            'children.*.children.*.result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'children.*.children.*.reference_general' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_male' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_female' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_child_male' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_child_female' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.unit' => ['nullable', 'string', 'max:60'],
            'children.*.children.*.predefined_values' => ['nullable', 'array', 'max:30'],
            'children.*.children.*.predefined_values.*' => ['required', 'string', 'max:100', 'distinct'],
            'children.*.children.*.display_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'children.*.children.*.is_active' => ['sometimes', 'boolean'],
            'children.*.children.*.is_bold' => ['sometimes', 'boolean'],
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
