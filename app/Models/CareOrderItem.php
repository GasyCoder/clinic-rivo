<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One requested act within a CareOrder, snapshotting the catalog at order time. */
#[Fillable([
    'care_order_id', 'catalog_item_id', 'catalog_item_code_snapshot',
    'catalog_item_name_snapshot', 'quantity', 'instructions',
    'not_performed_at', 'not_performed_reason', 'not_performed_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class CareOrderItem extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'not_performed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function careOrder(): BelongsTo
    {
        return $this->belongsTo(CareOrder::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function careRecordProcedures(): HasMany
    {
        return $this->hasMany(CareRecordProcedure::class);
    }

    public function notPerformedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'not_performed_by');
    }

    /** Backend-trustworthy sum of what Soins actually linked to this item — never a Vue counter. */
    public function realizedQuantity(): string
    {
        return number_format((float) $this->careRecordProcedures()->sum('quantity'), 2, '.', '');
    }

    public function remainingQuantity(): string
    {
        $remaining = (float) $this->quantity - (float) $this->realizedQuantity();

        return number_format(max($remaining, 0), 2, '.', '');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Withdrawn by the doctor before Soins took the patient: nothing left to do. */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isResolved(): bool
    {
        return $this->isCancelled()
            || $this->not_performed_at !== null
            || (float) $this->remainingQuantity() <= 0.0;
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }
}
