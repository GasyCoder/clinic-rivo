<?php

namespace App\Actions\Care;

use App\Enums\CareCompletionMode;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveAndCompleteCareAction
{
    public function __construct(
        private readonly SaveCareRecordAction $saveRecord,
        private readonly CompleteCareAndOrientToMedicineAction $completeCare,
    ) {}

    /**
     * `$destination` : la suite choisie à l'étape Terminer, `null` pour
     * suivre le parcours prévu (ADR-166).
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        EpisodeOrientation $orientation,
        array $data,
        User $actor,
        ?CareCompletionMode $destination = null,
        ?string $finishReason = null,
    ): EpisodeOrientation {
        return DB::transaction(function () use ($orientation, $data, $actor, $destination, $finishReason) {
            $this->saveRecord->execute($orientation, $data, $actor, $destination);

            return $this->completeCare->execute(
                $orientation,
                $actor,
                $destination,
                $finishReason,
            );
        });
    }
}
