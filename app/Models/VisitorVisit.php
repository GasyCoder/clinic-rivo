<?php

namespace App\Models;

use App\Enums\VisitorCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical visit recorded at Reception. This is intentionally not a
 * patient record and has no relationship with episodes, billing or cash.
 * Records are retained and closed through checked_out_at rather than deleted.
 */
#[Fillable([
    'full_name', 'phone', 'category', 'organization',
    'patient_id', 'reason',
    'checked_in_at', 'checked_out_at', 'created_by', 'closed_by',
])]
class VisitorVisit extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'category' => VisitorCategory::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(VisitorVisitAttachment::class)->chaperone();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isPresent(): bool
    {
        return $this->checked_out_at === null;
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
