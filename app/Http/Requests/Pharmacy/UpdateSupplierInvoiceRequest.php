<?php

namespace App\Http\Requests\Pharmacy;

class UpdateSupplierInvoiceRequest extends StoreSupplierInvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supplier_invoices.update') === true;
    }
}
