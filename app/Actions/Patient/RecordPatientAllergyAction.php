<?php

namespace App\Actions\Patient;

use App\Enums\AllergySeverity;
use App\Models\AllergenReference;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RecordPatientAllergyAction
{
    public function execute(
        Patient $patient,
        string $substance,
        ?string $reaction = null,
        ?AllergySeverity $severity = null,
        ?User $actor = null,
        ?AllergenReference $reference = null,
    ): PatientAllergy {
        return $patient->allergies()->create([
            'allergen_reference_id' => $reference?->getKey(),
            'substance' => $substance,
            'reaction' => $reaction,
            'severity' => $severity,
            'recorded_by' => $actor?->getKey() ?? Auth::id(),
        ]);
    }
}
