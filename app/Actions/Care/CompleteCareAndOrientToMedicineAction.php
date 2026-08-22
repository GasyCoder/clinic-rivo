<?php

namespace App\Actions\Care;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompleteCareAndOrientToMedicineAction
{
    public function __construct(private readonly CreateEpisodeOrientationAction $createOrientation) {}

    public function execute(
        EpisodeOrientation $orientation,
        User $actor,
        bool $orientUnknownNeedToMedicine = false,
    ): EpisodeOrientation {
        return DB::transaction(function () use ($orientation, $actor, $orientUnknownNeedToMedicine): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with('episode.serviceRequests')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Care) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas le service Soins.');
            }

            $locked->complete($actor);

            $requests = $locked->episode->serviceRequests;
            $hasPlannedMedicine = $requests->contains(
                fn ($request) => in_array($request->routing_mode, [
                    ReceptionRoutingMode::MedicineDirect,
                    ReceptionRoutingMode::CareThenMedicine,
                ], true),
            );
            $isUnknownNeed = $locked->episode->designation_deferred;

            if ($hasPlannedMedicine || ($isUnknownNeed && $orientUnknownNeedToMedicine)) {
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
