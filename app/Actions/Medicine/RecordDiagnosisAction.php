<?php

namespace App\Actions\Medicine;

use App\Enums\DiagnosisType;
use App\Models\Consultation;
use App\Models\Diagnosis;
use Illuminate\Support\Facades\Auth;

class RecordDiagnosisAction
{
    public function execute(Consultation $consultation, DiagnosisType $type, string $description): Diagnosis
    {
        return $consultation->diagnoses()->create([
            'type' => $type,
            'description' => $description,
            'recorded_by' => Auth::id(),
        ]);
    }
}
