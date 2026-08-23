<?php

namespace App\Actions\Medicine;

use App\Enums\DiagnosisType;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RecordDiagnosisAction
{
    public function execute(Consultation $consultation, DiagnosisType $type, string $description, ?User $actor = null): Diagnosis
    {
        return $consultation->diagnoses()->create([
            'type' => $type,
            'description' => $description,
            'recorded_by' => $actor?->getKey() ?? Auth::id(),
        ]);
    }
}
