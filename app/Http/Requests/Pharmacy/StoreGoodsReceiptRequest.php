<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // ADR-179 — une ligne solde une ligne de commande, ou constate un
            // article livré sans avoir été commandé. Dans ce second cas elle
            // nomme le produit : un médicament de la clinique, ou une ligne du
            // catalogue de ce fournisseur (même résolution qu'à la commande).
            'lines.*.purchase_order_line_id' => [
                'nullable', 'integer',
                Rule::exists('purchase_order_lines', 'id')->where('purchase_order_id', $this->route('purchaseOrder')?->id),
            ],
            'lines.*.medicine_uuid' => [
                'nullable', 'uuid',
                Rule::exists('medicines', 'uuid')->where('active', true),
            ],
            'lines.*.supplier_catalog_item_uuid' => [
                'nullable', 'uuid',
                Rule::exists('supplier_catalog_items', 'uuid')->whereNull('deleted_at'),
            ],
            'lines.*.quantity_received' => ['required', 'integer', 'min:1'],
            'lines.*.lot_number' => ['required', 'string', 'max:100'],
            'lines.*.expires_at' => ['required', 'date'],
            'lines.*.unit_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            // ADR-174 — le nom sous lequel la pharmacie vendra un produit nouveau.
            'lines.*.sale_name' => ['nullable', 'string', 'max:255'],

            // ADR-175 — la facture peut accompagner la réception, ou venir plus tard.
            'invoice' => ['nullable', 'array'],
            'invoice.invoice_number' => ['required_with:invoice', 'string', 'max:100'],
            'invoice.invoice_date' => ['required_with:invoice', 'date'],
            'invoice.due_date' => ['nullable', 'date', 'after_or_equal:invoice.invoice_date'],
            'invoice.total_amount' => ['required_with:invoice', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'invoice.notes' => ['nullable', 'string', 'max:2000'],
            'invoice.attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
        ];
    }

    /**
     * ADR-179 — chaque ligne doit désigner quelque chose. Une règle
     * `required_without_all` sur `lines.*` compare des champs littéraux et non
     * ceux de la même ligne : le contrôle se fait donc ligne par ligne.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_values($this->input('lines', [])) as $index => $line) {
                $designates = filled($line['purchase_order_line_id'] ?? null)
                    || filled($line['medicine_uuid'] ?? null)
                    || filled($line['supplier_catalog_item_uuid'] ?? null);

                if (! $designates) {
                    $validator->errors()->add(
                        "lines.{$index}.medicine_uuid",
                        'Indiquez le produit reçu : une ligne de la commande, ou un article livré hors commande.',
                    );
                }
            }
        });
    }

    protected function passedValidation(): void
    {
        // ADR-182, amendement du 2026-09-24 — faire entrer au catalogue un
        // produit livré depuis le catalogue du fournisseur ne demande que le
        // droit de réceptionner ; l'action le vérifie, refus individuel compris.

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
