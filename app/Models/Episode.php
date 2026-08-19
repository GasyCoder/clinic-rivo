<?php

namespace App\Models;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Exceptions\InvalidEpisodeTransitionException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CDC §21. Represents one patient visit/passage, opened at Réception and
 * tracked through orientation, care and billing via three independently
 * evolving status columns (§21: "le statut médical, financier et
 * administratif doit rester séparé"). `medical_status`/`financial_status`
 * are plain nullable strings here — their valid values belong to modules
 * not yet built (Médecine, Facture/Caisse) and are deliberately left for
 * those modules to define, not guessed in this one.
 *
 * No SoftDeletable: §21's schema does not list deleted_at for episodes
 * (unlike patients), and ADR-010 prefers cancel/correct/reverse over
 * deletion for critical records — an episode is cancelled via cancel(),
 * never soft-deleted.
 */
#[Fillable(['patient_id', 'episode_number', 'status', 'medical_status', 'financial_status', 'administrative_status', 'started_at', 'ended_at', 'created_by'])]
class Episode extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'status' => EpisodeStatus::class,
            'administrative_status' => EpisodeAdministrativeStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * PENDING_ORIENTATION → ORIENTED. The only administrative_status
     * transition this module owns; ORIENTED → IN_CARE → PENDING_SETTLEMENT
     * → DISCHARGED belong to whichever future module actually receives,
     * medically discharges (§34.1 "sortie médicale"), and then
     * administratively closes (§34.1.2 "sortie administrative", gated on
     * the patient's balance) the episode, and are not implemented here.
     */
    public function orient(): void
    {
        if ($this->administrative_status !== EpisodeAdministrativeStatus::PendingOrientation) {
            throw new InvalidEpisodeTransitionException(
                $this,
                EpisodeAdministrativeStatus::Oriented->value,
                $this->administrative_status->value,
            );
        }

        $this->administrative_status = EpisodeAdministrativeStatus::Oriented;
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
