<?php

namespace App\Actions\Episode;

use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\Patient;
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

    public function execute(Patient $patient, EpisodePriority $priority = EpisodePriority::Normal): Episode
    {
        return DB::transaction(function () use ($patient, $priority): Episode {
            $episode = Episode::create([
                'patient_id' => $patient->id,
                'episode_number' => $this->numbers->next(),
                'status' => EpisodeStatus::Open,
                'priority' => $priority,
                // Emergency care starts operationally at once. The detailed
                // queues below still keep Soins and Médecine independent.
                'administrative_status' => $priority === EpisodePriority::Emergency
                    ? EpisodeAdministrativeStatus::Oriented
                    : EpisodeAdministrativeStatus::PendingOrientation,
                'started_at' => now(),
                'created_by' => Auth::id(),
            ]);

            // Every normal passage first enters the nursing/care queue.
            $this->createOrientation->execute(
                $episode,
                CatalogModule::Reception,
                CatalogModule::Care,
            );

            // Emergency is the only admission that also becomes visible to
            // Medicine immediately; dossier completion and payment never
            // block these two operational queues.
            if ($priority === EpisodePriority::Emergency) {
                $this->createOrientation->execute(
                    $episode,
                    CatalogModule::Reception,
                    CatalogModule::Medicine,
                    reason: 'Admission en urgence.',
                );
            }

            return $episode->load('orientations');
        });
    }
}
