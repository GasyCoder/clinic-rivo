<?php

namespace App\Models;

use App\Enums\ConsultationDecision;
use App\Enums\ConsultationEvolution;
use App\Enums\ConsultationStatus;
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
#[Fillable([
    'episode_id', 'episode_orientation_id', 'doctor_id', 'status',
    'chief_complaint', 'reason', 'symptom_onset', 'evolution',
    'additional_notes', 'known_treatment_change',
    'known_treatment_change_notes', 'reported_allergies',
    'reported_antecedents', 'reported_habitual_treatments',
    'interviewed_by', 'interviewed_at', 'clinical_exam', 'decision',
    'decision_notes', 'consulted_at', 'completed_at', 'completed_by',
    'cancelled_at', 'cancelled_by', 'cancellation_reason',
])]
class Consultation extends Model
{
    use Auditable, SoftDeletable;

    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'decision' => ConsultationDecision::class,
            'evolution' => ConsultationEvolution::class,
            'reported_allergies' => 'array',
            'reported_antecedents' => 'array',
            'reported_habitual_treatments' => 'array',
            'consulted_at' => 'datetime',
            'interviewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The single gate for "may this still be written to". Closure used to
     * be inferred from a MedicalDischarge existing, which mistook one
     * possible decision for the only way an encounter can end.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
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

    /** Who closed the encounter. Null for rows closed before this status existed. */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** ADR-163 — qui a annulé une visite de service ouverte par erreur. */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function interviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewed_by');
    }

    /**
     * Treatments the patient was already taking at this encounter — the
     * DOSSIER MÉDICAL's "traitements actuels". Never a prescription.
     */
    public function currentTreatments(): HasMany
    {
        return $this->hasMany(ConsultationCurrentTreatment::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The structured clinical examination: general condition, consciousness
     * and one status per body system. `clinical_exam` keeps the doctor's free
     * notes beside it — one home for that text, never two.
     */
    public function clinicalExamination(): HasOne
    {
        return $this->hasOne(ClinicalExamination::class);
    }

    /**
     * Where each step of this encounter stands, as the doctor decided —
     * never derived from whether some data happens to exist.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ConsultationStep::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function labRequests(): HasMany
    {
        return $this->hasMany(LabRequest::class);
    }

    public function imagingRequests(): HasMany
    {
        return $this->hasMany(ImagingRequest::class);
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
     * Every conduite à tenir this encounter went through, cancelled ones
     * included: changing course is legitimate and stays readable (ADR-084).
     */
    public function orientations(): HasMany
    {
        return $this->hasMany(ConsultationOrientation::class)->orderBy('id');
    }

    /**
     * The one that currently stands. Guarded by `active_key` rather than by
     * a "latest wins" ordering, so two tabs cannot leave two live.
     */
    public function activeOrientation(): HasOne
    {
        return $this->hasOne(ConsultationOrientation::class)
            ->whereNotNull('active_key')
            ->latestOfMany('id');
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
