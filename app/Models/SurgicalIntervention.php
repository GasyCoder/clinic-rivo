<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CDC GitHub §15/16 — surgery.intervention.create/update.
 */
#[Fillable(['surgical_request_id', 'performed_by', 'started_at', 'ended_at', 'procedure_summary', 'notes'])]
class SurgicalIntervention extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
