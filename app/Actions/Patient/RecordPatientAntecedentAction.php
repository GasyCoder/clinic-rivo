<?php

namespace App\Actions\Patient;

use App\Enums\PatientAntecedentType;
use App\Models\Patient;
use App\Models\PatientAntecedent;
use Illuminate\Support\Facades\Auth;

class RecordPatientAntecedentAction
{
    public function execute(
        Patient $patient,
        string $description,
        PatientAntecedentType $type = PatientAntecedentType::Personal,
    ): PatientAntecedent {
        return $patient->antecedents()->create([
            'type' => $type,
            'description' => $description,
            'recorded_by' => Auth::id(),
        ]);
    }
}
