<?php

namespace App\Support;

use App\Enums\CareCompletionMode;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Models\Episode;
use Illuminate\Support\Collection;

/**
 * Single source of truth for what happens after the nursing stage.
 * Hospital admission/discharge is intentionally outside this workflow:
 * those are physician-owned decisions handled by the hospitalization and
 * medical-discharge modules.
 */
class CareWorkflow
{
    public function completionMode(Episode $episode): CareCompletionMode
    {
        $requests = $this->serviceRequests($episode);

        if ($episode->designation_deferred || $requests->isEmpty()) {
            return CareCompletionMode::Choice;
        }

        $requiresMedicine = $requests->contains(
            fn ($request) => in_array($request->routing_mode, [
                ReceptionRoutingMode::MedicineDirect,
                ReceptionRoutingMode::CareThenMedicine,
            ], true),
        );

        return $requiresMedicine
            ? CareCompletionMode::Medicine
            : CareCompletionMode::Finish;
    }

    public function expectsMedicalTransmission(Episode $episode): bool
    {
        return $episode->priority === EpisodePriority::Emergency
            || $this->completionMode($episode) !== CareCompletionMode::Finish;
    }

    public function recommendsRoutineVitals(Episode $episode): bool
    {
        return $episode->priority === EpisodePriority::Emergency
            || $this->completionMode($episode) !== CareCompletionMode::Finish
            || $this->serviceRequests($episode)->contains('care_recommends_vitals', true);
    }

    public function requiresAllergyCheck(Episode $episode): bool
    {
        return $this->serviceRequests($episode)->contains('care_requires_allergy_check', true);
    }

    /** @return Collection<int, mixed> */
    private function serviceRequests(Episode $episode): Collection
    {
        return $episode->relationLoaded('serviceRequests')
            ? $episode->serviceRequests
            : $episode->serviceRequests()->get();
    }
}
