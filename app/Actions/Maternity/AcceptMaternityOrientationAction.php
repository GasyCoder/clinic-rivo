<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogModule;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AcceptMaternityOrientationAction
{
    public function execute(EpisodeOrientation $orientation, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($orientation, $actor): EpisodeOrientation {
            $locked = EpisodeOrientation::query()->with('episode')->lockForUpdate()->findOrFail($orientation->id);
            if ($locked->destination_module !== CatalogModule::Maternity) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas la Maternité.');
            }
            $locked->accept($actor);
            $locked->episode->startCare();

            return $locked->fresh(['episode.patient']);
        });
    }
}
