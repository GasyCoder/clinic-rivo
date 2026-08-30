<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTreatmentPhase;
use App\Models\SurgicalTreatmentItem;
use App\Services\Audit\Auditor;
use Illuminate\Validation\ValidationException;

class RemoveSurgicalTreatmentItemAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(SurgicalTreatmentItem $item, SurgicalTreatmentPhase $expectedPhase): void
    {
        $item->loadMissing('surgicalRequest');

        if ($item->phase !== $expectedPhase) {
            throw ValidationException::withMessages(['treatment' => 'Cette ligne de traitement ne correspond pas au volet demandé.']);
        }

        $locked = $expectedPhase === SurgicalTreatmentPhase::Preliminary
            ? $item->surgicalRequest->status === SurgicalRequestStatus::InProgress
                || $item->surgicalRequest->status === SurgicalRequestStatus::Completed
                || $item->surgicalRequest->status === SurgicalRequestStatus::Discharged
            : $item->surgicalRequest->status === SurgicalRequestStatus::Discharged;

        if ($locked) {
            throw ValidationException::withMessages(['treatment' => 'Cette ligne de traitement fait désormais partie de l’historique clinique.']);
        }

        $this->auditor->record('delete', entity: $item, module: 'surgery');
        $item->delete();
    }
}
