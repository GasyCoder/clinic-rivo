<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicineCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('medicine_categories.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('medicine_categories', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
