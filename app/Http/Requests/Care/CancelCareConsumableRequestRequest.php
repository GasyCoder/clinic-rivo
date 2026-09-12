<?php

namespace App\Http\Requests\Care;

use Illuminate\Foundation\Http\FormRequest;

class CancelCareConsumableRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('care_consumables.cancel') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Le motif d’annulation est obligatoire.',
        ];
    }
}
