<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-098 — correcting a line transcribed from a supplier's document. Same
 * fields, and the same tolerance on the price, as the import itself: a
 * catalogue may legitimately carry no price (the price is then required
 * when the line is attached to a medicine).
 */
class UpdateSupplierCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supplier_catalogs.update') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:120'],
            'medicine_label' => ['required', 'string', 'max:255'],
            'presentation' => ['nullable', 'string', 'max:255'],
            'family_label' => ['nullable', 'string', 'max:120'],
            'supplier_price' => ['nullable', 'numeric', 'gte:0', 'max:999999999999.99', 'decimal:0,2'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'reference' => 'référence',
            'medicine_label' => 'médicament',
            'presentation' => 'présentation',
            'family_label' => 'famille',
            'supplier_price' => 'prix fournisseur',
        ];
    }
}
