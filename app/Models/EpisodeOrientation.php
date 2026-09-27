<?php

namespace App\Models;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Exceptions\InvalidEpisodeOrientationTransitionException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Persistent, audited hand-off between two operational services.
 *
 * There is no delete lifecycle: a clinical hand-off is completed or
 * cancelled and remains in the episode history. `active_key` is populated
 * only while waiting/in progress, which prevents two concurrent active
 * queues for the same episode and destination while still allowing a later
 * re-orientation when the clinical workflow requires it.
 */
#[Fillable([
    'episode_id', 'source_module', 'destination_module', 'status', 'active_key',
    'reason', 'oriented_by', 'accepted_by', 'completed_by', 'oriented_at',
    'accepted_at', 'completed_at', 'completion_reason',
    'taken_over_at', 'taken_over_from', 'takeover_reason',
])]
class EpisodeOrientation extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'source_module' => CatalogModule::class,
            'destination_module' => CatalogModule::class,
            'status' => EpisodeOrientationStatus::class,
            'oriented_at' => 'datetime',
            'accepted_at' => 'datetime',
            'taken_over_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function orientedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oriented_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * ADR-166 — une fin de Soins hors du parcours prévu porte un motif. Elle a
     * mené soit au médecin (une orientation Soins → Médecine ouverte à ce
     * moment-là), soit à la fin aux Soins. `null` pour une fin ordinaire.
     *
     * @param  iterable<self>  $episodeOrientations  les orientations du même passage
     */
    public function offPlanOutcome(iterable $episodeOrientations): ?string
    {
        if (blank($this->completion_reason) || $this->completed_at === null) {
            return null;
        }

        foreach ($episodeOrientations as $other) {
            if ($other->destination_module === CatalogModule::Medicine
                && $other->source_module === CatalogModule::Care
                && $other->oriented_at !== null
                && $other->oriented_at->greaterThanOrEqualTo($this->completed_at)) {
                return 'MEDICINE';
            }
        }

        return 'FINISH';
    }

    /** ADR-167 — le soignant à qui le patient a été repris en dernier. */
    public function takenOverFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_over_from');
    }

    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class, 'episode_orientation_id');
    }

    public function maternityRecord(): HasOne
    {
        return $this->hasOne(MaternityRecord::class, 'episode_orientation_id');
    }

    public function accept(User $actor): void
    {
        if ($this->status !== EpisodeOrientationStatus::Pending) {
            throw new InvalidEpisodeOrientationTransitionException(
                $this,
                EpisodeOrientationStatus::InProgress->value,
                $this->status->value,
            );
        }

        $this->status = EpisodeOrientationStatus::InProgress;
        $this->accepted_by = $actor->getKey();
        $this->accepted_at = now();
        $this->save();
    }

    /**
     * `$reason` ne sert qu'à une fin qui s'écarte du parcours prévu — un
     * patient attendu en Médecine terminé aux Soins (ADR-166). Une fin
     * ordinaire n'a rien à justifier et laisse la colonne vide.
     */
    public function complete(User $actor, ?string $reason = null): void
    {
        if ($this->status !== EpisodeOrientationStatus::InProgress) {
            throw new InvalidEpisodeOrientationTransitionException(
                $this,
                EpisodeOrientationStatus::Completed->value,
                $this->status->value,
            );
        }

        $this->status = EpisodeOrientationStatus::Completed;
        $this->completed_by = $actor->getKey();
        $this->completed_at = now();
        $this->completion_reason = $reason;
        $this->active_key = null;
        $this->save();
    }

    /**
     * Withdraws an orientation the destination has not taken up yet — the
     * doctor changed course before anyone acted on it (ADR-084).
     *
     * Only from PENDING, deliberately: once a service has accepted the
     * patient, the sending module no longer disposes of that work, and the
     * transition refuses rather than unpicking it silently. The row stays
     * in the episode history; only `active_key` is released so another
     * orientation toward the same destination becomes possible.
     */
    public function cancel(User $actor): void
    {
        if ($this->status !== EpisodeOrientationStatus::Pending) {
            throw new InvalidEpisodeOrientationTransitionException(
                $this,
                EpisodeOrientationStatus::Cancelled->value,
                $this->status->value,
            );
        }

        $this->status = EpisodeOrientationStatus::Cancelled;
        $this->completed_by = $actor->getKey();
        $this->completed_at = now();
        $this->active_key = null;
        $this->save();
    }

    /**
     * Withdraws an orientation that was taken up at once, before its work
     * produced anything that must survive (ADR-113, ADR-163).
     *
     * Two flows take an orientation up at creation — the automatic admission
     * of a stay, and the ward round opened from it — so `cancel()`, which only
     * accepts PENDING, can never undo them. This method is reserved to the
     * actions that have first checked, under lock, that nothing lasting was
     * recorded: it is not a general way out of IN_PROGRESS.
     */
    public function cancelTakenUp(User $actor): void
    {
        if ($this->status !== EpisodeOrientationStatus::InProgress) {
            throw new InvalidEpisodeOrientationTransitionException(
                $this,
                EpisodeOrientationStatus::Cancelled->value,
                $this->status->value,
            );
        }

        $this->status = EpisodeOrientationStatus::Cancelled;
        $this->completed_by = $actor->getKey();
        $this->completed_at = now();
        $this->active_key = null;
        $this->save();
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }
}
