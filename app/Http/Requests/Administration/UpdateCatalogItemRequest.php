<?php

namespace App\Http\Requests\Administration;

use App\Enums\CatalogModule;
use Illuminate\Foundation\Http\FormRequest;
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
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
