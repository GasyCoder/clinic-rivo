<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id', 'medicine_id', 'medicine_supplier_offer_id',
    'quantity_ordered', 'unit_price', 'line_total', 'quantity_received',
])]
class PurchaseOrderLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity_received' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplierOffer::class, 'medicine_supplier_offer_id');
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function quantityRemaining(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
