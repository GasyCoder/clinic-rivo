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
    'accepted_at', 'completed_at',
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

    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class, 'episode_orientation_id');
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

    public function complete(User $actor): void
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
        $this->active_key = null;
        $this->save();
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }
}
