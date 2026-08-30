<?php

namespace App\Models;

use App\Enums\CareOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A doctor's request for one or more Soins acts made during a Consultation —
 * the DEMANDE, kept distinct from the EpisodeOrientation that carries it
 * (ORIENTATION), the CareRecordProcedure Soins actually performs (ACTE
 * RÉALISÉ) and any BillableItem that may follow (FACTURATION).
 */
#[Fillable([
    'episode_id', 'consultation_id', 'source_orientation_id', 'care_orientation_id',
    'requested_by', 'instructions', 'requires_return_to_medicine', 'status',
    'ordered_at', 'completed_at',
])]
class CareOrder extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'status' => CareOrderStatus::class,
            'requires_return_to_medicine' => 'boolean',
            'ordered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function sourceOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'source_orientation_id');
    }

    public function careOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'care_orientation_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CareOrderItem::class);
    }

    /**
     * Display-only projection of progress, computed from item resolution
     * rather than a second persisted flag that could drift out of sync:
     * `status` itself only ever transitions PENDING → COMPLETED, driven by
     * the Care orientation's own completion (CompleteCareAndOrientToMedicineAction).
     */
    public function displayStatus(): string
    {
        if ($this->status === CareOrderStatus::Completed) {
            return 'COMPLETED';
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with('careRecordProcedures')->get();

        return $items->contains(fn (CareOrderItem $item) => $item->realizedQuantity() !== '0.00' || $item->not_performed_at !== null)
            ? 'IN_PROGRESS'
            : 'PENDING';
    }

    public function hasUnresolvedItems(): bool
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with('careRecordProcedures')->get();

        return $items->contains(fn (CareOrderItem $item) => ! $item->isResolved());
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }
}
