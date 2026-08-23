<?php

namespace App\Actions\Care;

use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveAndCompleteCareAction
{
    public function __construct(
        private readonly SaveCareRecordAction $saveRecord,
        private readonly CompleteCareAndOrientToMedicineAction $completeCare,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        EpisodeOrientation $orientation,
        array $data,
        User $actor,
        bool $orientToMedicine = false,
    ): EpisodeOrientation {
        return DB::transaction(function () use ($orientation, $data, $actor, $orientToMedicine) {
            $this->saveRecord->execute($orientation, $data, $actor);

            return $this->completeCare->execute(
                $orientation,
                $actor,
                $orientToMedicine,
            );
        });
    }
}
