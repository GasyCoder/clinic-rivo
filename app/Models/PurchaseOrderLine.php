<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id', 'medicine_id', 'medicine_supplier_offer_id',
    'quantity_ordered', 'unit_price', 'line_total', 'quantity_received',
    'shortage_at', 'shortage_reason', 'shortage_by',
    'external_shortage_by_uuid', 'external_shortage_by_name',
])]
class PurchaseOrderLine extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity_received' => 'integer',
            'shortage_at' => 'datetime',
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

    public function shortageBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shortage_by');
    }

    /** ADR-179 — le fournisseur ne livrera pas ce qui reste de cette ligne. */
    public function isShort(): bool
    {
        return $this->shortage_at !== null;
    }

    /**
     * Ce que la commande attend encore. Une ligne en rupture n'attend plus
     * rien : c'est ce qui la fait disparaître de l'écran de réception et
     * laisse la commande se clore.
     */
    public function quantityRemaining(): int
    {
        if ($this->isShort()) {
            return 0;
        }

        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    /** Plus rien n'est attendu sur cette ligne : livrée en entier, ou abandonnée. */
    public function isSettled(): bool
    {
        return $this->isShort() || $this->quantity_received >= $this->quantity_ordered;
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
