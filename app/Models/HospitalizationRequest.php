<?php

namespace App\Models;

use App\Enums\ClinicalPriority;
use App\Enums\MedicalRequestStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the doctor asks the clinic to do: admit this patient.
 *
 * It is a DEMANDE, exactly like `SurgicalRequest` — never the stay itself.
 * The admission, the bed, the ward round and the discharge from the ward
 * belong to an Hospitalisation module that does not exist yet, and whose
 * rules the CDC does not define; `requested_admission_at` is therefore the
 * date the doctor asks for, never a recorded admission (ADR-032, ADR-074).
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
