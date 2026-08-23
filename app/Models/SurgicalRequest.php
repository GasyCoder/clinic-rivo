<?php

namespace App\Models;

use App\Enums\SurgicalRequestStatus;
use App\Exceptions\InvalidSurgicalRequestTransitionException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CDC GitHub §15/16 — root of the Chirurgie module: one row per surgical
 * case, opened for an episode. No `patient_id`: reached via
 * episode->patient, same reasoning as Consultation. HasUuid because, like
 * Episode/Prescription, a surgical case could plausibly be referenced
 * across an inter-site transfer (ADR-005) — sub-resources below are not
 * given a uuid, same as Diagnosis/PrescriptionLine.
 *
 * No SoftDeletable: CDC §15/16 lists no surgery.delete/restore/force_delete/
 * cancel permission at all, unlike Consultation or Prescription — see the
 * migration for the flagged conflict with §11's generic critical-data rule.
 */
#[Fillable([
    'episode_id', 'catalog_item_id', 'requested_by', 'surgeon_id', 'status',
    'procedure_name', 'procedure_details', 'notes',
    'operating_room', 'preparation_notes', 'scheduled_at',
    'preoperative_notes', 'preoperative_assessed_by', 'preoperative_assessed_at',
    'preoperative_validated_by', 'preoperative_validated_at',
    'completed_at', 'discharged_by', 'discharged_at', 'discharge_notes', 'created_by',
])]
class SurgicalRequest extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'status' => SurgicalRequestStatus::class,
            'scheduled_at' => 'datetime',
            'preoperative_assessed_at' => 'datetime',
            'preoperative_validated_at' => 'datetime',
            'completed_at' => 'datetime',
            'discharged_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surgeon_id');
    }

    public function preoperativeAssessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preoperative_assessed_by');
    }

    public function preoperativeValidatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preoperative_validated_by');
    }

    public function dischargedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discharged_by');
    }

    public function intervention(): HasOne
    {
        return $this->hasOne(SurgicalIntervention::class);
    }

    public function anesthesiaRecord(): HasOne
    {
        return $this->hasOne(AnesthesiaRecord::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(SurgicalReport::class);
    }

    public function complications(): HasMany
    {
        return $this->hasMany(SurgicalComplication::class);
    }

    public function consumables(): HasMany
    {
        return $this->hasMany(SurgicalConsumable::class);
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(SurgicalTeamMember::class);
    }

    public function careNotes(): HasMany
    {
        return $this->hasMany(SurgicalCareNote::class);
    }

    public function blockEntry(): HasOne
    {
        return $this->hasOne(SurgicalBlockEntry::class);
    }

    public function blockExit(): HasOne
    {
        return $this->hasOne(SurgicalBlockExit::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(SurgicalPostoperativeObservation::class)->oldest('observed_at');
    }

    public function treatmentItems(): HasMany
    {
        return $this->hasMany(SurgicalTreatmentItem::class)->oldest('id');
    }

    /**
     * PENDING → SCHEDULED (surgery.schedule). Sets the lead surgeon and the
     * planned date/time in one step — the CDC does not separate "assign a
     * surgeon" from "pick a date" into two permissions.
     *
     * Also usable to CORRECT a scheduling mistake (wrong surgeon/date typed)
     * as long as the intervention hasn't started yet: called again while
     * already SCHEDULED or PREOPERATIVE_VALIDATED just updates the two
     * fields without a further status change. surgery.schedule is the only
     * CDC permission covering this data, so re-using it for corrections
     * (rather than inventing a separate "correct" permission) matches
     * ADR-010's spirit — once IN_PROGRESS the case is no longer just
     * "planned data" and correction is out of scope here.
     */
    public function schedule(User $surgeon, string $scheduledAt): void
    {
        if (! in_array($this->status, [
            SurgicalRequestStatus::Pending,
            SurgicalRequestStatus::Scheduled,
            SurgicalRequestStatus::PreoperativeValidated,
        ], true)) {
            throw new InvalidSurgicalRequestTransitionException(
                $this,
                SurgicalRequestStatus::Scheduled->value,
                $this->status->value,
            );
        }

        $this->surgeon_id = $surgeon->getKey();
        $this->scheduled_at = $scheduledAt;

        if ($this->status === SurgicalRequestStatus::Pending) {
            $this->status = SurgicalRequestStatus::Scheduled;
        }

        $this->save();
    }

    /**
     * SCHEDULED → PREOPERATIVE_VALIDATED (surgery.preoperative.validate).
     */
    public function validatePreoperative(User $validator): void
    {
        if ($this->status !== SurgicalRequestStatus::Scheduled) {
            throw new InvalidSurgicalRequestTransitionException(
                $this,
                SurgicalRequestStatus::PreoperativeValidated->value,
                $this->status->value,
            );
        }

        $this->preoperative_validated_by = $validator->getKey();
        $this->preoperative_validated_at = now();
        $this->status = SurgicalRequestStatus::PreoperativeValidated;
        $this->save();
    }

    /**
     * PREOPERATIVE_VALIDATED → IN_PROGRESS. Called by
     * CreateSurgicalInterventionAction — the intervention actually starting
     * is itself the evidence, same pattern as Episode::startCare().
     */
    public function startIntervention(): void
    {
        if ($this->status !== SurgicalRequestStatus::PreoperativeValidated) {
            throw new InvalidSurgicalRequestTransitionException(
                $this,
                SurgicalRequestStatus::InProgress->value,
                $this->status->value,
            );
        }

        $this->status = SurgicalRequestStatus::InProgress;
        $this->save();
    }

    /**
     * IN_PROGRESS → COMPLETED. Called by ValidateSurgicalReportAction once
     * the operative report is validated (surgery.report.validate).
     */
    public function complete(): void
    {
        if ($this->status !== SurgicalRequestStatus::InProgress) {
            throw new InvalidSurgicalRequestTransitionException(
                $this,
                SurgicalRequestStatus::Completed->value,
                $this->status->value,
            );
        }

        $this->status = SurgicalRequestStatus::Completed;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * COMPLETED → DISCHARGED (surgery.discharge.create). Terminal.
     */
    public function discharge(User $actor, ?string $notes): void
    {
        if ($this->status !== SurgicalRequestStatus::Completed) {
            throw new InvalidSurgicalRequestTransitionException(
                $this,
                SurgicalRequestStatus::Discharged->value,
                $this->status->value,
            );
        }

        $this->discharged_by = $actor->getKey();
        $this->discharged_at = now();
        $this->discharge_notes = $notes;
        $this->status = SurgicalRequestStatus::Discharged;
        $this->save();
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
