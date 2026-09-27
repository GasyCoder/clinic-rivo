<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-171 — réinitialiser un dossier du bloc : droit dédié, motif obligatoire. */
class ResetSurgicalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('surgery.reset') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Le motif de la réinitialisation est obligatoire.',
            'reason.min' => 'Le motif de la réinitialisation est obligatoire.',
        ];
    }
}
