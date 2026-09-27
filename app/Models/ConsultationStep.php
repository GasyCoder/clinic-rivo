<?php

namespace App\Models;

use App\Enums\ConsultationStep as StepKey;
use App\Enums\ConsultationStepStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one step of a consultation stands, as decided by the doctor.
 *
 * Server-owned on purpose: the browser used to derive progress from
 * whatever data happened to be present, which cannot tell a step declared
 * unnecessary (SKIPPED) from one merely left blank, and marked
 * Prescription complete as soon as any prescription existed. Every
 * transition is audited under the `medical` module.
 */
#[Fillable([
    'consultation_id', 'step', 'status', 'completed_at', 'completed_by',
    'skip_reason',
])]
class ConsultationStep extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'step' => StepKey::class,
            'status' => ConsultationStepStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
