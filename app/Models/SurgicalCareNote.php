<?php

namespace App\Models;

use App\Enums\SurgicalCarePhase;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (see migration) — covers both surgery.care.create
 * (peropératoire) and surgery.postoperative_care.create (postopératoire),
 * distinguished by `phase`.
 */
#[Fillable(['surgical_request_id', 'phase', 'note', 'recorded_by', 'recorded_at'])]
class SurgicalCareNote extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'phase' => SurgicalCarePhase::class,
            'recorded_at' => 'datetime',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
