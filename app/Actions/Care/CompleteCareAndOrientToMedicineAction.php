<?php

namespace App\Actions\Care;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompleteCareAndOrientToMedicineAction
{
    public function __construct(private readonly CreateEpisodeOrientationAction $createOrientation) {}

    public function execute(EpisodeOrientation $orientation, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($orientation, $actor): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Care) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas le service Soins.');
            }

            $locked->complete($actor);

            $this->createOrientation->execute(
                $locked->episode,
                CatalogModule::Care,
                CatalogModule::Medicine,
                $actor,
                'Orientation vers Médecine après évaluation aux Soins.',
            );

            return $locked->fresh(['episode.patient']);
        });
    }
}
