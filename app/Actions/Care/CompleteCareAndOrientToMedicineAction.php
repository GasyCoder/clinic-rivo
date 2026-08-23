<?php

namespace App\Actions\Care;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CareCompletionMode;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Exceptions\InvalidEpisodeOrientationTransitionException;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Support\CareWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CompleteCareAndOrientToMedicineAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly CareWorkflow $careWorkflow,
    ) {}

    public function execute(
        EpisodeOrientation $orientation,
        User $actor,
        bool $orientUnknownNeedToMedicine = false,
    ): EpisodeOrientation {
        return DB::transaction(function () use ($orientation, $actor, $orientUnknownNeedToMedicine): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with(['episode.serviceRequests', 'episode.careRecord.procedures'])
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Care) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas le service Soins.');
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress) {
                throw new InvalidEpisodeOrientationTransitionException(
                    $locked,
                    EpisodeOrientationStatus::Completed->value,
                    $locked->status->value,
                );
            }

            $completionMode = $this->careWorkflow->completionMode($locked->episode);
            $isUnknownNeed = $completionMode === CareCompletionMode::Choice;
            $procedureCount = $locked->episode->careRecord?->procedures->count() ?? 0;
            $noProcedureReason = $locked->episode->careRecord?->no_procedure_reason;

            if ($orientUnknownNeedToMedicine && ! $isUnknownNeed) {
                throw ValidationException::withMessages([
                    'orientation' => 'Ce parcours possède déjà une destination clinique définie.',
                ]);
            }

            if ($completionMode === CareCompletionMode::Finish && $procedureCount === 0) {
                throw ValidationException::withMessages([
                    'procedures' => 'Enregistrez au moins un acte réellement réalisé avant de terminer les soins.',
                ]);
            }

            if ($isUnknownNeed
                && ! $orientUnknownNeedToMedicine
                && $procedureCount === 0
                && blank($noProcedureReason)) {
                throw ValidationException::withMessages([
                    'no_procedure_reason' => 'Indiquez pourquoi aucun acte n’a été réalisé, ou orientez le patient vers Médecine.',
                ]);
            }

            $locked->complete($actor);

            if ($completionMode === CareCompletionMode::Medicine
                || ($isUnknownNeed && $orientUnknownNeedToMedicine)) {
                $this->createOrientation->execute(
                    $locked->episode,
                    CatalogModule::Care,
                    CatalogModule::Medicine,
                    $actor,
                    $isUnknownNeed
                        ? 'Orientation explicite après évaluation d’un besoin initialement inconnu.'
                        : 'Orientation vers Médecine selon le parcours planifié.',
                );
            }

            return $locked->fresh(['episode.patient']);
        });
    }

    /** Explicit convenience entry point for an initially unknown need. */
    public function executeForUnknownNeed(
        EpisodeOrientation $orientation,
        User $actor,
    ): EpisodeOrientation {
        return $this->execute($orientation, $actor, orientUnknownNeedToMedicine: true);
    }
}
