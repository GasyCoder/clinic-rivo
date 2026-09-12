<?php

namespace App\Http\Requests\Administration;

use Illuminate\Foundation\Http\FormRequest;

class SyncCareActConsumablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.items.update') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // An empty array is legitimate: it clears every suggestion.
            'consumables' => ['present', 'array', 'max:20'],
            'consumables.*.medicine_uuid' => ['required', 'uuid', 'distinct', 'exists:medicines,uuid'],
            'consumables.*.default_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'consumables.*.medicine_uuid.distinct' => 'Ce consommable est déjà associé à cet acte : ajustez sa quantité.',
        ];
    }
}
