<?php

namespace App\Models;

use App\Enums\ClinicalPriority;
use App\Enums\MedicalRequestStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What the doctor asks the clinic to do: admit this patient.
 *
 * It is a DEMANDE, exactly like `SurgicalRequest` — never the stay itself.
 * Since ADR-113 the stay is a separate `HospitalStay`, opened automatically
 * when this request is sent and ended by the doctor's medical discharge;
 * `requested_admission_at` remains the date the doctor asks for, and the
 * recorded admission lives on the stay.
 *
 * No site column: each site runs its own database (ADR-001, ADR-025), so
 * the site is implicit. Admitting at another site is a Référence/Transfert,
 * which is a different orientation with its own record.
 */
#[Fillable([
    'episode_id', 'consultation_id', 'episode_orientation_id',
    'reason', 'admission_diagnosis', 'clinical_summary', 'planned_treatment',
    'requested_service', 'requested_admission_at', 'priority', 'instructions',
    'status', 'requested_by', 'requested_at',
    'cancelled_by', 'cancelled_at', 'cancellation_reason',
])]
class HospitalizationRequest extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'priority' => ClinicalPriority::class,
            'status' => MedicalRequestStatus::class,
            'requested_admission_at' => 'datetime',
            'requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function episodeOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function hospitalStay(): HasOne
    {
        return $this->hasOne(HospitalStay::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Clinical record: corrected or cancelled, never destroyed (ADR-010). */
    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
