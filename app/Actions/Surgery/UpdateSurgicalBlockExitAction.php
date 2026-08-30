<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalBlockExit;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSurgicalBlockExitAction
{
    public function execute(SurgicalRequest $surgicalRequest, array $data, User $actor): SurgicalBlockExit
    {
        return DB::transaction(function () use ($surgicalRequest, $data, $actor): SurgicalBlockExit {
            $locked = SurgicalRequest::query()->lockForUpdate()->findOrFail($surgicalRequest->getKey());

            if (! in_array($locked->status, [SurgicalRequestStatus::InProgress, SurgicalRequestStatus::Completed], true)) {
                throw ValidationException::withMessages([
                    'block_exit' => 'La fiche de sortie du bloc est disponible après le démarrage de l’intervention et avant la sortie de chirurgie.',
                ]);
            }

            $exit = $locked->blockExit()->firstOrNew();
            $exit->fill($data);
            $exit->created_by ??= $actor->getKey();
            $exit->updated_by = $actor->getKey();
            $exit->save();

            return $exit->fresh();
        });
    }
}
