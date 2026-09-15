<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-098 — the counted quantity of the lots checked during an inventory. */
class StoreInventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stock.adjust') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'counts' => ['required', 'array', 'min:1', 'max:5000'],
            'counts.*.lot_uuid' => ['required', 'uuid', 'distinct', 'exists:medicine_lots,uuid'],
            'counts.*.counted_quantity' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'counts.required' => 'Saisissez au moins une quantité comptée.',
            'counts.*.counted_quantity.min' => 'Une quantité comptée ne peut pas être négative.',
            'reason.required' => 'Indiquez le motif de l’inventaire (par exemple « Inventaire mensuel de septembre »).',
            'reason.min' => 'Le motif doit comporter au moins 3 caractères.',
        ];
    }
}
