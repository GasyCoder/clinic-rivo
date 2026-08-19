<?php

namespace App\Actions\Patient;

use App\Enums\AllergySeverity;
use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Support\Facades\Auth;

class RecordPatientAllergyAction
{
    public function execute(Patient $patient, string $substance, ?string $reaction = null, ?AllergySeverity $severity = null): PatientAllergy
    {
        return $patient->allergies()->create([
            'substance' => $substance,
            'reaction' => $reaction,
            'severity' => $severity,
            'recorded_by' => Auth::id(),
        ]);
    }
}
