<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalPostoperativeObservation;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordSurgicalPostoperativeObservationAction
{
    public function execute(SurgicalRequest $surgicalRequest, array $data, User $actor): SurgicalPostoperativeObservation
    {
        return DB::transaction(function () use ($surgicalRequest, $data, $actor): SurgicalPostoperativeObservation {
            $locked = SurgicalRequest::query()->lockForUpdate()->findOrFail($surgicalRequest->getKey());

            if (! in_array($locked->status, [SurgicalRequestStatus::InProgress, SurgicalRequestStatus::Completed], true)) {
                throw ValidationException::withMessages([
                    'observation' => 'Les constantes postopératoires sont enregistrées après le démarrage de l’intervention et avant la sortie.',
                ]);
            }

            return $locked->observations()->create([
                ...$data,
                'recorded_by' => $actor->getKey(),
            ]);
        });
    }
}
