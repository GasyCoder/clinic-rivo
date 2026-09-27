<?php

namespace App\Models;

use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationStatus;
use App\Enums\ConsultationOrientationType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The conduite à tenir of one consultation, and the link to the request it
 * produced.
 *
 * Only one may be active per consultation at a time — guarded by
 * `active_key`, the same nullable-unique device as `episode_orientations`
 * and `cash_sessions`. Changing one's mind cancels the previous one and its
 * request; it never deletes either (ADR-010).
 */
#[Fillable([
    'consultation_id', 'type', 'status', 'priority',
    'episode_orientation_id', 'surgical_request_id', 'medical_discharge_id',
    'hospitalization_request_id', 'medical_referral_id',
    'selected_by', 'selected_at', 'submitted_at',
    'cancelled_by', 'cancelled_at', 'cancellation_reason', 'active_key',
])]
class ConsultationOrientation extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'type' => ConsultationOrientationType::class,
            'status' => ConsultationOrientationStatus::class,
            'priority' => ClinicalPriority::class,
            'selected_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Held only while the orientation is active. Releasing it on
     * cancellation is what lets the doctor choose another destination
     * without the previous row ever being removed.
     */
    public static function activeKeyFor(Consultation $consultation): string
    {
        return 'CONSULTATION_'.$consultation->getKey();
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function episodeOrientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class);
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function medicalDischarge(): BelongsTo
    {
        return $this->belongsTo(MedicalDischarge::class);
    }

    public function hospitalizationRequest(): BelongsTo
    {
        return $this->belongsTo(HospitalizationRequest::class);
    }

    public function medicalReferral(): BelongsTo
    {
        return $this->belongsTo(MedicalReferral::class);
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Whether the receiving service actually has something to act on.
     * Selecting "Chirurgie" without filling the request tells the block
     * nothing, which is why closure checks this rather than the type.
     */
    public function isSubmitted(): bool
    {
        return $this->status === ConsultationOrientationStatus::Submitted;
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
