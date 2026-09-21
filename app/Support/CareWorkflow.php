<?php

namespace App\Support;

use App\Enums\CareCompletionMode;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
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

    /**
     * `$chosen` : la suite que l'infirmier a choisie à l'étape Terminer
     * (ADR-166). Envoyer vers Médecine un patient prévu aux Soins seuls ouvre
     * la transmission ; sans choix, c'est le parcours prévu qui décide.
     */
    public function expectsMedicalTransmission(Episode $episode, ?CareCompletionMode $chosen = null): bool
    {
        return $chosen === CareCompletionMode::Medicine
            || $episode->priority === EpisodePriority::Emergency
            || $this->completionMode($episode) !== CareCompletionMode::Finish;
    }

    /**
     * Médecine a déjà ce patient pour ce passage — en attente, en cours ou
     * terminé : une urgence ouvre les deux files d'emblée, et un médecin a pu
     * clôturer avant la fin des Soins. Seule une orientation retirée ne compte
     * pas (ADR-085). Un seul endroit pour la règle : l'écran et l'action de
     * fin des Soins la lisent tous deux.
     */
    public function medicineAlreadyInvolved(Episode $episode, bool $forUpdate = false): bool
    {
        $query = EpisodeOrientation::query()
            ->where('episode_id', $episode->getKey())
            ->where('destination_module', CatalogModule::Medicine->value)
            ->where('status', '!=', EpisodeOrientationStatus::Cancelled->value);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->exists();
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
