<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('goods_receipts.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => [
                'required', 'integer',
                Rule::exists('purchase_order_lines', 'id')->where('purchase_order_id', $this->route('purchaseOrder')?->id),
            ],
            'lines.*.quantity_received' => ['required', 'integer', 'min:1'],
            'lines.*.lot_number' => ['required', 'string', 'max:100'],
            'lines.*.expires_at' => ['required', 'date'],
            'lines.*.unit_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    protected function passedValidation(): void
    {
        $hasPrice = collect($this->input('lines', []))->contains(fn (array $line) => filled($line['unit_purchase_price'] ?? null));

        if ($hasPrice && ! $this->user()?->can('stock.cost.record')) {
            abort(403, 'Vous ne pouvez pas enregistrer un prix d’achat.');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.*.purchase_order_line_id.exists' => 'Cette ligne n’appartient pas à cette commande.',
            'lines.*.quantity_received.min' => 'La quantité reçue doit être supérieure à zéro.',
            'lines.*.lot_number.required' => 'Le numéro de lot est obligatoire.',
            'lines.*.expires_at.required' => 'La date de péremption est obligatoire.',
        ];
    }
}
