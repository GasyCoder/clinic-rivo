<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SaveMaternityRecordAction
{
    /** @param array<string, mixed> $data */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): MaternityRecord
    {
        return DB::transaction(function () use ($orientation, $data, $actor): MaternityRecord {
            $locked = EpisodeOrientation::query()->with(['episode.maternityRecord'])->lockForUpdate()->findOrFail($orientation->id);
            if ($locked->destination_module !== CatalogModule::Maternity) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas la Maternité.');
            }
            if ($locked->status !== EpisodeOrientationStatus::InProgress || $locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['maternity_record' => 'Le dossier est modifiable uniquement pendant une prise en charge Maternité active.']);
            }

            $record = $locked->episode->maternityRecord;
            if ($record) {
                $record->fill($data + ['updated_by' => $actor->id])->save();
            } else {
                $record = MaternityRecord::query()->create([
                    'episode_id' => $locked->episode_id,
                    'episode_orientation_id' => $locked->id,
                    ...$data,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            return $record->fresh(['procedures.performer']);
        });
    }
}
