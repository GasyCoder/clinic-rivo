<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_number', 'medicine_supplier_id', 'status', 'ordered_at', 'expected_delivery_at',
    'total_amount', 'currency', 'notes', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
    'created_by', 'updated_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_updated_by_uuid', 'external_updated_by_name',
    'external_cancelled_by_uuid', 'external_cancelled_by_name',
])]
class PurchaseOrder extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    /**
     * ADR-175 — seul un brouillon jamais envoyé peut quitter la corbeille
     * pour de bon : une commande envoyée a engagé la clinique auprès d'un
     * tiers, elle reste dans l'histoire.
     */
    public function isForceDeleteProtected(): bool
    {
        return $this->status !== PurchaseOrderStatus::Draft
            || $this->receipts()->exists()
            || $this->invoices()->exists();
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'ordered_at' => 'datetime',
            'expected_delivery_at' => 'date',
            'total_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplier::class, 'medicine_supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Le statut suit ce qui est réellement arrivé : il est recalculé après
     * chaque réception, et après une quantité corrigée à l'entrée en stock.
     */
    public function refreshReceptionStatus(): void
    {
        $this->load('lines');
        $fullyReceived = $this->lines->every(fn (PurchaseOrderLine $line) => $line->quantity_received >= $line->quantity_ordered);
        $anyReceived = $this->lines->contains(fn (PurchaseOrderLine $line) => $line->quantity_received > 0);

        $status = match (true) {
            $fullyReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Ordered,
        };

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
