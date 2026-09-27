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
    'supplier_confirmed_at', 'supplier_confirmation_reference', 'supplier_confirmation_notes',
    'supplier_confirmation_attachment_path', 'supplier_confirmation_attachment_original_name',
    'supplier_confirmation_attachment_mime_type', 'supplier_confirmation_attachment_size',
    'supplier_confirmed_by', 'external_supplier_confirmed_by_uuid', 'external_supplier_confirmed_by_name',
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
            'supplier_confirmed_at' => 'datetime',
        ];
    }

    /** ADR-179 — le fournisseur a accusé cette commande. Jamais obligatoire. */
    public function isSupplierConfirmed(): bool
    {
        return $this->supplier_confirmed_at !== null;
    }

    public function supplierConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_confirmed_by');
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
        if ($this->status === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $this->load('lines');
        $fullyReceived = $this->lines->every(fn (PurchaseOrderLine $line) => $line->quantity_received >= $line->quantity_ordered);
        // ADR-179 — une ligne en rupture n'attend plus rien : sans elle, une
        // seule ligne jamais livrée laissait la commande « Partiellement
        // reçue » à vie, donc éternellement dans « À réceptionner ».
        $settled = $this->lines->every(fn (PurchaseOrderLine $line) => $line->isSettled());
        $anyReceived = $this->lines->contains(fn (PurchaseOrderLine $line) => $line->quantity_received > 0);

        $status = match (true) {
            $fullyReceived => PurchaseOrderStatus::Received,
            $settled => PurchaseOrderStatus::Closed,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Ordered,
        };

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }

    /** Ce que la commande attend encore, ruptures déduites. */
    public function hasOutstandingLines(): bool
    {
        return $this->lines()->get()->contains(fn (PurchaseOrderLine $line) => ! $line->isSettled());
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
