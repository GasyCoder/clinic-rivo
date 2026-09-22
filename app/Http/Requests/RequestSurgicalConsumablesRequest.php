<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-169 — le matériel du bloc, choisi dans le stock de la Pharmacie. Seuls
 * l'UUID du produit et la quantité voyagent : aucun libellé, aucun prix. Le
 * serveur relit le produit et résout le tarif (ADR-036).
 */
class RequestSurgicalConsumablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('surgery.consumables.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.medicine_uuid' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Ajoutez au moins un produit.',
            'lines.*.medicine_uuid.distinct' => 'Un même produit ne s’ajoute qu’une fois : réglez sa quantité.',
            'lines.*.quantity.min' => 'La quantité doit être au moins 1.',
        ];
    }
}
