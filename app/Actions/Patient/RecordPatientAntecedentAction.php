<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use App\Models\PatientAntecedent;
use Illuminate\Support\Facades\Auth;

class RecordPatientAntecedentAction
{
    public function execute(Patient $patient, string $description): PatientAntecedent
    {
        return $patient->antecedents()->create([
            'description' => $description,
            'recorded_by' => Auth::id(),
        ]);
    }
}
