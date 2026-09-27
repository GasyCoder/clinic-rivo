<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-179 — constater qu'un article commandé ne sera pas livré.
 *
 * Le droit est celui de la réception : c'est la personne qui a la livraison
 * sous les yeux qui voit ce qui manque (ADR-176).
 */
class MarkPurchaseOrderLineShortageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('goods_receipts.create') === true;
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
            'reason.required' => 'Indiquez pourquoi cet article ne sera pas livré.',
        ];
    }
}
