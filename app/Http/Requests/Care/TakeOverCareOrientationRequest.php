<?php

namespace App\Http\Requests\Care;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-167 — reprendre un patient exige de dire pourquoi. */
class TakeOverCareOrientationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('care.complete');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez pourquoi vous reprenez ce patient.',
            'reason.max' => 'Le motif ne peut pas dépasser 1 000 caractères.',
        ];
    }
}
