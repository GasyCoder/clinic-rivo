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
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            // ADR-112 — le nom sous lequel la pharmacie vendra un produit nouveau.
            'lines.*.sale_name' => ['nullable', 'string', 'max:255'],

            // ADR-113 — la facture peut accompagner la réception, ou venir plus tard.
            'invoice' => ['nullable', 'array'],
            'invoice.invoice_number' => ['required_with:invoice', 'string', 'max:100'],
            'invoice.invoice_date' => ['required_with:invoice', 'date'],
            'invoice.due_date' => ['nullable', 'date', 'after_or_equal:invoice.invoice_date'],
            'invoice.total_amount' => ['required_with:invoice', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'invoice.notes' => ['nullable', 'string', 'max:2000'],
            'invoice.attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
        ];
    }

    protected function passedValidation(): void
    {
        $hasPrice = collect($this->input('lines', []))->contains(fn (array $line) => filled($line['unit_purchase_price'] ?? null));

        if ($hasPrice && ! $this->user()?->can('stock.cost.record')) {
            abort(403, 'Vous ne pouvez pas enregistrer un prix d’achat.');
        }

        $renames = collect($this->input('lines', []))->contains(fn (array $line) => filled($line['sale_name'] ?? null));

        if ($renames && ! $this->user()?->can('medicines.name.update')) {
            abort(403, 'Vous ne pouvez pas renommer un médicament.');
        }

        if ($this->filled('invoice') && ! $this->user()?->can('supplier_invoices.create')) {
            abort(403, 'Vous ne pouvez pas enregistrer la facture du fournisseur.');
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
            'invoice.invoice_number.required_with' => 'Le numéro de la facture est obligatoire.',
            'invoice.invoice_date.required_with' => 'La date de la facture est obligatoire.',
            'invoice.total_amount.required_with' => 'Le montant de la facture est obligatoire.',
            'invoice.due_date.after_or_equal' => 'L’échéance ne peut pas précéder la date de la facture.',
        ];
    }
}
