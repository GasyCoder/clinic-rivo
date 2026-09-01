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
    'episode_id', 'consultation_id', 'source_orientation_id', 'lab_orientation_id',
    'requested_by', 'notes', 'requested_at',
])]
class LabRequest extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return ['requested_at' => 'datetime'];
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
    public function displayStatus(): string
    {
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
}
