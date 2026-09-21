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
 * Referring the patient out: another establishment, or another site of the
 * clinic.
 *
 * Holds what the receiving team needs to read — facility, reason, current
 * diagnosis, clinical summary, what has already been given or prescribed —
 * all of it pre-filled from the interview, the examination, the paraclinical
 * results and the active prescription. The doctor corrects; they never
 * retype (§17).
 *
 * The transfer itself is not modelled: no departure, no arrival, no
 * acknowledgement. Whether the patient left, and what the destination did,
 * is outside anything the CDC defines today, and a "transferred" status
 * written by the sending doctor would claim a fact nobody observed.
 */
#[Fillable([
    'episode_id', 'hospital_stay_id', 'consultation_id', 'episode_orientation_id',
    'facility', 'reason', 'diagnosis', 'clinical_summary', 'treatments_given',
    'priority', 'recommendations', 'notes',
    'status', 'referred_by', 'referred_at',
    'cancelled_by', 'cancelled_at', 'cancellation_reason',
    'departed_at', 'departed_by', 'departure_notes',
])]
class MedicalReferral extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'priority' => ClinicalPriority::class,
            'status' => MedicalRequestStatus::class,
            'referred_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'departed_at' => 'datetime',
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

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function departedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'departed_by');
    }

    /** ADR-114 — le patient a réellement quitté la clinique. */
    public function hasDeparted(): bool
    {
        return $this->departed_at !== null;
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

    /** ADR-162 — la demande faite depuis le séjour, sans consultation. */
    public function hospitalStay(): BelongsTo
    {
        return $this->belongsTo(HospitalStay::class);
    }
}
