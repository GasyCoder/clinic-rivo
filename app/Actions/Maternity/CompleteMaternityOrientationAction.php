<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteMaternityOrientationAction
{
    public function execute(EpisodeOrientation $orientation, User $actor): MaternityRecord
    {
        return DB::transaction(function () use ($orientation, $actor): MaternityRecord {
            $locked = EpisodeOrientation::query()
                ->with('episode.maternityRecord')
                ->lockForUpdate()
                ->findOrFail($orientation->id);

            if ($locked->destination_module !== CatalogModule::Maternity) {
                abort(404);
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'maternity_record' => 'La prise en charge Maternité n’est pas active.',
                ]);
            }

            $record = $locked->episode->maternityRecord;

            if (! $record) {
                throw ValidationException::withMessages([
                    'maternity_record' => 'Enregistrez le dossier Maternité avant de terminer.',
                ]);
            }

            $locked->complete($actor);
            $record->forceFill([
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            return $record->fresh();
        });
    }
}
