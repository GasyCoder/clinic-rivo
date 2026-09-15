<?php

namespace App\Http\Requests\Pharmacy;

class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase_orders.update') === true;
    }
}
