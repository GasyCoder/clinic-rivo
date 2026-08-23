<?php

namespace App\Models;

use App\Enums\MedicalDischargeType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only physician decision. It is deliberately independent from the
 * administrative exit and from any payment state (CDC §33 / ADR-035).
 */
#[Fillable([
    'episode_id', 'consultation_id', 'type', 'final_diagnosis',
    'patient_condition', 'discharge_prescription', 'recommendations',
    'follow_up_at', 'observations', 'transfer_destination',
    'death_occurred_at', 'death_place', 'death_causes', 'discharged_at',
    'created_by',
])]
class MedicalDischarge extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'type' => MedicalDischargeType::class,
            'follow_up_at' => 'datetime',
            'death_occurred_at' => 'datetime',
            'discharged_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
