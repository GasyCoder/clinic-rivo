<?php

namespace App\Actions\Episode;

use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\User;
use App\Services\Episode\EpisodeNumberGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only place an Episode gets created — mirrors CreatePatientAction's
 * role for Patient, so episode_number generation can never be bypassed.
 *
 * Deliberately does not check for an already-OPEN episode on the same
 * patient: the CDC does not state whether concurrent episodes are allowed,
 * and blocking them would be an invented business rule. Flagged for the
 * team to confirm if it turns out to matter in practice.
 */
class CreateEpisodeAction
{
    public function __construct(
        private readonly EpisodeNumberGenerator $numbers,
        private readonly CreateEpisodeOrientationAction $createOrientation,
    ) {}

    public function execute(
        Patient $patient,
        EpisodePriority $priority = EpisodePriority::Normal,
        ?User $actor = null,
    ): Episode {
        return DB::transaction(function () use ($patient, $priority, $actor): Episode {
            $episodeNumber = $this->numbers->next($patient);
            $episode = Episode::create([
                'patient_id' => $patient->id,
                'episode_number' => $episodeNumber,
                'visit_sequence' => $this->numbers->sequenceFromNumber($patient, $episodeNumber),
                'status' => EpisodeStatus::Open,
                'priority' => $priority,
                // Emergency care starts operationally at once. The detailed
                // queues below still keep Soins and Médecine independent.
                'administrative_status' => $priority === EpisodePriority::Emergency
                    ? EpisodeAdministrativeStatus::Oriented
                    : EpisodeAdministrativeStatus::PendingOrientation,
                'started_at' => now(),
                'created_by' => $actor?->getKey() ?? Auth::id(),
            ]);

            // A normal passage has no default queue: its known designations
            // are resolved by PlanEpisodeRoutingAction. Unknown need is an
            // explicit Reception choice and is handled by that caller, never
            // represented by a fictitious catalog item.
            //
            // Emergency bypasses this planning wait and is immediately
            // visible to both clinical services.
            if ($priority === EpisodePriority::Emergency) {
                $this->createOrientation->execute(
                    $episode,
                    CatalogModule::Reception,
                    CatalogModule::Care,
                    $actor,
                    reason: 'Admission en urgence.',
                );
                $this->createOrientation->execute(
                    $episode,
                    CatalogModule::Reception,
                    CatalogModule::Medicine,
                    $actor,
                    reason: 'Admission en urgence.',
                );
            }

            return $episode->load('orientations');
        });
    }
}
