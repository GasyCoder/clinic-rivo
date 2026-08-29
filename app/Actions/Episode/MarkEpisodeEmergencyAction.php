<?php

namespace App\Actions\Episode;

use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Requalifies one already-created passage as an emergency.
 *
 * Priority belongs to Episode, never Patient. This action is shared by
 * Reception and Medicine so both entry points apply exactly the same
 * validation, routing and audit rules.
 */
class MarkEpisodeEmergencyAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly Auditor $auditor,
    ) {}

    public function execute(
        Episode $episode,
        CatalogModule $source,
        User $actor,
        ?EpisodeOrientation $medicineOrientation = null,
    ): Episode {
        if ($actor->cannot('episodes.mark_emergency')) {
            throw new AuthorizationException('Vous ne pouvez pas classer ce passage en urgence.');
        }

        return DB::transaction(function () use ($episode, $source, $actor, $medicineOrientation): Episode {
            if ($source === CatalogModule::Medicine) {
                $medicineOrientation = $medicineOrientation === null
                    ? null
                    : EpisodeOrientation::query()
                        ->with('consultation')
                        ->lockForUpdate()
                        ->find($medicineOrientation->getKey());

                if (! $medicineOrientation
                    || $medicineOrientation->episode_id !== $episode->getKey()
                    || $medicineOrientation->destination_module !== CatalogModule::Medicine
                    || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress
                    || $medicineOrientation->consultation === null) {
                    throw ValidationException::withMessages([
                        'episode' => 'La Médecine peut classer le passage en urgence uniquement depuis sa Consultation active.',
                    ]);
                }
            }

            $episode = Episode::query()
                ->with('medicalDischarge')
                ->lockForUpdate()
                ->findOrFail($episode->getKey());

            if ($episode->status !== EpisodeStatus::Open || $episode->medicalDischarge !== null) {
                throw ValidationException::withMessages([
                    'episode' => 'Un passage fermé, annulé ou médicalement sorti ne peut plus être classé en urgence.',
                ]);
            }

            if ($episode->priority !== EpisodePriority::Emergency) {
                $oldValues = [
                    'priority' => $episode->priority->value,
                    'administrative_status' => $episode->administrative_status?->value,
                ];

                $episode->priority = EpisodePriority::Emergency;

                // A consultation already started must never regress from
                // IN_CARE to ORIENTED. Only an Episode still waiting for its
                // first routing advances to the administrative routed state.
                if ($episode->administrative_status === EpisodeAdministrativeStatus::PendingOrientation) {
                    $episode->administrative_status = EpisodeAdministrativeStatus::Oriented;
                }

                // One explicit, meaningful audit entry is clearer than the
                // generic model "update" event for this sensitive action.
                $episode->saveQuietly();

                $this->auditor->record(
                    'episode.mark_emergency',
                    entity: $episode,
                    oldValues: $oldValues,
                    newValues: [
                        'priority' => $episode->priority->value,
                        'administrative_status' => $episode->administrative_status?->value,
                    ],
                    reason: "Requalification après création du passage depuis {$source->label()}.",
                    module: strtolower($source->value),
                    actor: $actor,
                );
            }

            // Idempotent active_key handling prevents duplicate queues. If
            // Medicine is already in progress, that exact orientation stays
            // open and only the missing Soins alert is created.
            $this->createOrientation->execute(
                $episode,
                $source,
                CatalogModule::Care,
                $actor,
                reason: 'Passage classé en urgence après création de l’Épisode.',
            );
            $this->createOrientation->execute(
                $episode,
                $source,
                CatalogModule::Medicine,
                $actor,
                reason: 'Passage classé en urgence après création de l’Épisode.',
            );

            return $episode->load('orientations');
        });
    }
}
