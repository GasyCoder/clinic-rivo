<?php

namespace App\Actions\Episode;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\Patient;
use App\Services\Episode\EpisodeNumberGenerator;
use Illuminate\Support\Facades\Auth;

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
    public function __construct(private readonly EpisodeNumberGenerator $numbers) {}

    public function execute(Patient $patient): Episode
    {
        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => $this->numbers->next(),
            'status' => EpisodeStatus::Open,
            'administrative_status' => EpisodeAdministrativeStatus::PendingOrientation,
            'started_at' => now(),
            'created_by' => Auth::id(),
        ]);
    }
}
