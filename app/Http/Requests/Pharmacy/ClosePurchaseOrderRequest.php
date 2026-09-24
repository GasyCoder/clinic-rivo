<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-179 — solder une commande dont on n'attend plus rien.
 *
 * Renoncer à ce qui reste dû est une décision d'acheteur, d'où le droit
 * `purchase_orders.cancel` ; ce n'est pas une annulation pour autant — la
 * commande a été envoyée, souvent livrée en partie, et peut porter une facture.
 */
class ClosePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase_orders.cancel') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez pourquoi cette commande est clôturée sans être complète.',
        ];
    }
}
