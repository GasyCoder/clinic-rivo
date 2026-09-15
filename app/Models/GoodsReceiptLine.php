<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'goods_receipt_id', 'purchase_order_line_id', 'medicine_id', 'lot_number', 'expires_at',
    'quantity_received', 'unit_purchase_price', 'pharmacy_stock_movement_id',
])]
class GoodsReceiptLine extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'quantity_received' => 'integer',
            'unit_purchase_price' => 'decimal:2',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(PharmacyStockMovement::class, 'pharmacy_stock_movement_id');
    }
}
