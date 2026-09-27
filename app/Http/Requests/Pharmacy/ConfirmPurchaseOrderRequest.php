<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-179 — la confirmation qu'un fournisseur a envoyée sur une commande. */
class ConfirmPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase_orders.confirm') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // C'est une date du fournisseur, pas du système : elle se lit sur
            // son document. Elle ne peut pas être dans le futur.
            'confirmed_at' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmed_at.required' => 'Indiquez la date de la confirmation du fournisseur.',
            'confirmed_at.before_or_equal' => 'La confirmation ne peut pas être datée dans le futur.',
        ];
    }
}
