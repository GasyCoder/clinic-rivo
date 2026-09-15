<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierInvoiceRequest extends FormRequest
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
            'purchase_order_uuid' => ['nullable', 'uuid', 'exists:purchase_orders,uuid'],
            'goods_receipt_uuid' => ['nullable', 'uuid', 'exists:goods_receipts,uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx', 'max:10240'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.medicine_uuid' => ['required', 'uuid', 'exists:medicines,uuid'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
        ];
    }
}
