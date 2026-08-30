<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Laboratory\AnalysisReferenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordLabResultAction
{
    public function __construct(private readonly AnalysisReferenceResolver $references) {}

    public function execute(LabRequestItem $item, string $resultValue, ?string $resultNotes, User $actor): LabRequestItem
    {
        return DB::transaction(function () use ($item, $resultValue, $resultNotes, $actor): LabRequestItem {
            $locked = LabRequestItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($locked->resulted_at !== null) {
                throw ValidationException::withMessages([
                    'result_value' => 'Un résultat a déjà été enregistré pour cette analyse.',
                ]);
            }

            $locked->loadMissing(['catalogItem', 'labRequest.episode.patient']);
            $referenceDate = $locked->labRequest->episode->started_at ?? now();

            $locked->update([
                'result_value' => trim($resultValue),
                'result_notes' => filled($resultNotes) ? trim($resultNotes) : null,
                'reference_snapshot' => $this->references->snapshot(
                    $locked->catalogItem,
                    $locked->labRequest->episode->patient,
                    $referenceDate,
                ),
                'resulted_at' => now(),
                'resulted_by' => $actor->getKey(),
            ]);

            return $locked->fresh();
        });
    }
}
