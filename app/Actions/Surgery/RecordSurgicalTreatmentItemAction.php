<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTreatmentCategory;
use App\Enums\SurgicalTreatmentPhase;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTreatmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordSurgicalTreatmentItemAction
{
    public function execute(
        SurgicalRequest $surgicalRequest,
        SurgicalTreatmentPhase $phase,
        SurgicalTreatmentCategory $category,
        array $data,
        User $actor,
    ): SurgicalTreatmentItem {
        return DB::transaction(function () use ($surgicalRequest, $phase, $category, $data, $actor): SurgicalTreatmentItem {
            $locked = SurgicalRequest::query()->lockForUpdate()->findOrFail($surgicalRequest->getKey());
            $allowedStatuses = $phase === SurgicalTreatmentPhase::Preliminary
                ? [SurgicalRequestStatus::Pending, SurgicalRequestStatus::Scheduled, SurgicalRequestStatus::PreoperativeValidated]
                : [SurgicalRequestStatus::InProgress, SurgicalRequestStatus::Completed];

            if (! in_array($locked->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'treatment' => $phase === SurgicalTreatmentPhase::Preliminary
                        ? 'Le traitement préliminaire ne peut plus être modifié après le démarrage de l’intervention.'
                        : 'Le traitement postopératoire est enregistré après le démarrage de l’intervention et avant la sortie.',
                ]);
            }

            return $locked->treatmentItems()->create([
                ...$data,
                'phase' => $phase,
                'category' => $category,
                'recorded_by' => $actor->getKey(),
            ]);
        });
    }
}
