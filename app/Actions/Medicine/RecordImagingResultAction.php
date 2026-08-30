<?php

namespace App\Actions\Medicine;

use App\Models\ImagingRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordImagingResultAction
{
    public function execute(ImagingRequestItem $item, string $resultValue, ?string $resultNotes, User $actor): ImagingRequestItem
    {
        return DB::transaction(function () use ($item, $resultValue, $resultNotes, $actor): ImagingRequestItem {
            $locked = ImagingRequestItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($locked->resulted_at !== null) {
                throw ValidationException::withMessages([
                    'result_value' => 'Un compte rendu a déjà été enregistré pour cet examen.',
                ]);
            }

            $locked->update([
                'result_value' => trim($resultValue),
                'result_notes' => filled($resultNotes) ? trim($resultNotes) : null,
                'resulted_at' => now(),
                'resulted_by' => $actor->getKey(),
            ]);

            return $locked->fresh();
        });
    }
}
