<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A laboratory REQUEST from Medicine or Reception — kept distinct from the
 * EpisodeOrientation that routes the sample/technician (ORIENTATION) and the
 * per-item RESULT. Billing remains owned by Reception/Cash.
 */
#[Fillable([
    'episode_id', 'hospital_stay_id', 'consultation_id', 'source_orientation_id', 'lab_orientation_id',
    'requested_by', 'notes', 'requested_at',
    'cancelled_at', 'cancelled_by', 'cancel_reason',
    'archived_at', 'archived_by',
])]
class LabRequest extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'cancelled_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function labOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'lab_orientation_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabRequestItem::class);
    }

    /** Display-only, computed from item resolution — never a second persisted flag. */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Whether anything has already been produced for this request. A request
     * that carries a result is never cancellable: the act happened, and
     * ADR-010 forbids erasing it.
     */
    public function hasAnyResult(): bool
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return $items->contains(fn (LabRequestItem $item): bool => $item->resulted_at !== null);
    }

    public function displayStatus(): string
    {
        // A cancelled request is not "awaiting a result": it was
        // withdrawn, and the queues must stop counting it.
        if ($this->isCancelled()) {
            return 'CANCELLED';
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty() || $items->every(fn (LabRequestItem $item) => $item->resulted_at === null)) {
            return 'REQUESTED';
        }

        return $items->every(fn (LabRequestItem $item) => $item->resulted_at !== null)
            ? 'COMPLETED'
            : 'IN_PROGRESS';
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }

    /** ADR-162 — la demande faite depuis le séjour, sans consultation. */
    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }
}
