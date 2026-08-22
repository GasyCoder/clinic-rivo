<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AcceptMedicineOrientationAction
{
    public function execute(EpisodeOrientation $orientation, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($orientation, $actor): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Medicine) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas le service Médecine.');
            }

            $locked->accept($actor);
            $locked->episode->startCare();

            return $locked->fresh(['episode.patient']);
        });
    }
}
