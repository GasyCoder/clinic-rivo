<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalBlockEntry;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSurgicalBlockEntryAction
{
    public function execute(SurgicalRequest $surgicalRequest, array $data, User $actor): SurgicalBlockEntry
    {
        return DB::transaction(function () use ($surgicalRequest, $data, $actor): SurgicalBlockEntry {
            $locked = SurgicalRequest::query()->lockForUpdate()->findOrFail($surgicalRequest->getKey());

            if ($locked->status === SurgicalRequestStatus::Discharged) {
                throw ValidationException::withMessages([
                    'block_entry' => 'La fiche d’entrée au bloc ne peut plus être modifiée après la sortie.',
                ]);
            }

            $entry = $locked->blockEntry()->firstOrNew();
            $entry->fill($data);
            $entry->created_by ??= $actor->getKey();
            $entry->updated_by = $actor->getKey();
            $entry->save();

            return $entry->fresh();
        });
    }
}
