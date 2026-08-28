<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCatalogItemRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'unit' => trim((string) $this->input('unit')),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('catalog.items.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'module' => ['required', new Enum(CatalogModule::class)],
            'unit' => ['required', 'string', 'max:50'],
            'staff_coverage_policy' => ['sometimes', new Enum(StaffCoveragePolicy::class)],
            'reception_selectable' => ['sometimes', 'boolean'],
            'reception_routing_mode' => [
                'nullable',
                Rule::requiredIf($this->boolean('reception_selectable')),
                new Enum(ReceptionRoutingMode::class),
            ],
            'care_requires_allergy_check' => ['sometimes', 'boolean'],
            'care_recommends_vitals' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
