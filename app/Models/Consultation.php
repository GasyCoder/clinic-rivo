<?php

namespace App\Models;

use App\Enums\ConsultationDecision;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Client CDCF §6.1 — one clinical encounter within an episode. No
 * `patient_id`: the patient is reached via episode->patient, avoiding a
 * duplicated, independently-driftable foreign key.
 */
#[Fillable(['episode_id', 'episode_orientation_id', 'doctor_id', 'reason', 'clinical_exam', 'decision', 'decision_notes', 'consulted_at'])]
class Consultation extends Model
{
    use Auditable, SoftDeletable;

    protected function casts(): array
    {
        return [
            'decision' => ConsultationDecision::class,
            'consulted_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function orientation(): BelongsTo
    {
        return $this->belongsTo(EpisodeOrientation::class, 'episode_orientation_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function medicalDischarge(): HasOne
    {
        return $this->hasOne(MedicalDischarge::class);
    }

    /**
     * diagnoses/prescriptions both use restrictOnDelete() foreign keys
     * against consultation_id — without this override, force_delete on a
     * consultation with either would surface as a raw DB constraint
     * violation instead of the clean ForceDeleteForbiddenException every
     * other protected model produces (same reasoning as Patient's own
     * override).
     */
    public function isForceDeleteProtected(): bool
    {
        return $this->diagnoses()->exists()
            || $this->prescriptions()->exists()
            || $this->medicalDischarge()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
