<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-169 — annuler du matériel déclaré au bloc, tant que la Pharmacie n'a
 * rien sorti du stock (ADR-072). Même droit que la déclaration : le CDC ne
 * prévoit que surgery.consumables.create pour ce geste (§16).
 */
class CancelSurgicalConsumableRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('surgery.consumables.create') === true;
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
            'reason.min' => 'Le motif d’annulation doit être plus précis.',
        ];
    }
}
