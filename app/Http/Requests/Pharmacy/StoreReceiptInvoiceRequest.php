<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-113 — la facture d'une réception : un document global, sans ressaisir les produits. */
class StoreReceiptInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supplier_invoices.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'L’échéance ne peut pas précéder la date de la facture.',
        ];
    }
}
