<?php

namespace App\Models;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Exceptions\InvalidEpisodeTransitionException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CDC §21. Represents one patient visit/passage, opened at Réception and
 * tracked through orientation, care and billing via three independently
 * evolving status columns (§21: "le statut médical, financier et
 * administratif doit rester séparé"). `medical_status` is governed by the
 * Medicine workflow (ADR-035); `financial_status` remains owned by
 * Facture/Caisse and is never changed by Médecine.
 *
 * No SoftDeletable: §21's schema does not list deleted_at for episodes
 * (unlike patients), and ADR-010 prefers cancel/correct/reverse over
 * deletion for critical records — an episode is cancelled via cancel(),
 * never soft-deleted.
 */
#[Fillable([
    'patient_id', 'visit_sequence', 'episode_number', 'status', 'priority', 'medical_status',
    'financial_status', 'financial_mode', 'financial_context_completed_at',
    'financial_context_completed_by', 'administrative_status', 'designation_deferred',
    'service_plan_finalized_at', 'started_at', 'ended_at', 'created_by',
    'emergency_contact_name', 'emergency_contact_phone',
    'emergency_contact_relationship', 'emergency_contact_email',
])]
class Episode extends Model
{
    use Auditable, HasUuid;

    protected $attributes = [
        'priority' => 'NORMAL',
    ];

    protected function casts(): array
    {
        return [
            'status' => EpisodeStatus::class,
            'priority' => EpisodePriority::class,
            'medical_status' => EpisodeMedicalStatus::class,
            'financial_mode' => EpisodeFinancialMode::class,
            'administrative_status' => EpisodeAdministrativeStatus::class,
            'financial_context_completed_at' => 'datetime',
            'designation_deferred' => 'boolean',
            'service_plan_finalized_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        // An Episode is an immutable historical passage. Its Patient must
        // remain readable when the administrative dossier is soft-deleted.
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function orientations(): HasMany
    {
        return $this->hasMany(EpisodeOrientation::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function billableItems(): HasMany
    {
        return $this->hasMany(BillableItem::class);
    }

    public function mutualCoverage(): HasOne
    {
        return $this->hasOne(EpisodeMutualCoverage::class);
    }

    public function staffCoverage(): HasOne
    {
        return $this->hasOne(EpisodeStaffCoverage::class);
    }

    public function financialContextCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'financial_context_completed_by');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(EpisodeServiceRequest::class);
    }

    public function receptionJourneyDraft(): HasOne
    {
        return $this->hasOne(ReceptionJourneyDraft::class);
    }

    public function careRecord(): HasOne
    {
        return $this->hasOne(CareRecord::class);
    }

    public function medicalDischarge(): HasOne
    {
        return $this->hasOne(MedicalDischarge::class);
    }

    public function surgicalRequests(): HasMany
    {
        return $this->hasMany(SurgicalRequest::class);
    }

    /**
     * ORIENTED (emergency/legacy) or PENDING_ORIENTATION → IN_CARE.
     * Called when Soins or Médecine actually accepts an operational
     * orientation. It is deliberately a silent no-op once already IN_CARE or past it
     * (PENDING_SETTLEMENT/DISCHARGED): a second or third consultation
     * within the same episode is completely normal and must not throw.
     */
    public function startCare(): void
    {
        if (! in_array($this->administrative_status, [
            EpisodeAdministrativeStatus::PendingOrientation,
            EpisodeAdministrativeStatus::Oriented,
        ], true)) {
            return;
        }

        $this->administrative_status = EpisodeAdministrativeStatus::InCare;
        $this->save();
    }

    /**
     * Terminal — CLOSED and CANCELLED are both final, an episode is never
     * reopened. Records its own 'cancel' audit entry (with the reason) via
     * auditableSkipsChange() below rather than through Auditable's generic
     * 'update' hook, so the audit trail carries exactly one entry per
     * cancellation instead of a redundant pair.
     */
    public function cancel(string $reason): void
    {
        if ($this->status !== EpisodeStatus::Open) {
            throw new InvalidEpisodeTransitionException(
                $this,
                EpisodeStatus::Cancelled->value,
                $this->status->value,
            );
        }

        $this->status = EpisodeStatus::Cancelled;
        $this->ended_at = now();
        $this->save();

        app(Auditor::class)->record(
            'cancel',
            entity: $this,
            reason: $reason,
            module: $this->auditModule(),
        );
    }

    protected function auditableSkipsChange(array $changes): bool
    {
        return $this->status === EpisodeStatus::Cancelled && array_key_exists('status', $changes);
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
