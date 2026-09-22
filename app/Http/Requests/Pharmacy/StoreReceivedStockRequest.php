<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-113 — les lignes réceptionnées que le pharmacien fait entrer au stock. */
class StoreReceivedStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('stock.entry') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.uuid' => ['required', 'uuid', 'distinct', 'exists:goods_receipt_lines,uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.lot_number' => ['required', 'string', 'max:100'],
            'lines.*.expires_at' => ['required', 'date', 'after_or_equal:today'],
            'lines.*.sale_price' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'lines.*.sale_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function passedValidation(): void
    {
        $lines = collect($this->input('lines', []));

        if ($lines->contains(fn (array $line) => filled($line['sale_price'] ?? null)) && ! $this->user()?->can('medicines.sale_price.update')) {
            abort(403, 'Vous ne pouvez pas fixer un prix de vente.');
        }

        if ($lines->contains(fn (array $line) => filled($line['sale_name'] ?? null)) && ! $this->user()?->can('medicines.name.update')) {
            abort(403, 'Vous ne pouvez pas renommer un médicament.');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'Cochez au moins une ligne à entrer en stock.',
            'lines.*.uuid.exists' => 'Cette ligne de réception n’existe plus.',
            'lines.*.quantity.min' => 'La quantité doit être d’au moins 1.',
            'lines.*.lot_number.required' => 'Le numéro de lot est obligatoire.',
            'lines.*.expires_at.required' => 'La date de péremption est obligatoire.',
            'lines.*.expires_at.after_or_equal' => 'La péremption ne peut pas précéder l’entrée en stock.',
        ];
    }
}
