<?php

namespace App\Actions\Pediatrics;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** ADR-114 — la Pédiatrie prend en charge un patient orienté par Médecine. */
class AcceptPediatricsOrientationAction
{
    public function execute(EpisodeOrientation $orientation, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($orientation, $actor): EpisodeOrientation {
            $locked = EpisodeOrientation::query()->with('episode')->lockForUpdate()->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Pediatrics) {
                throw ValidationException::withMessages(['orientation' => 'Cette orientation ne concerne pas la Pédiatrie.']);
            }

            if ($locked->status !== EpisodeOrientationStatus::Pending) {
                throw ValidationException::withMessages(['orientation' => 'Ce patient est déjà pris en charge.']);
            }

            $locked->accept($actor);
            $locked->episode->startCare();

            return $locked->fresh(['episode.patient']);
        });
    }
}
