<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (see migration) — surgery.complications.create only.
 */
#[Fillable(['surgical_request_id', 'description', 'reported_by', 'reported_at'])]
class SurgicalComplication extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
