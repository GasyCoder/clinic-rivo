<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use App\Services\Patient\PatientBirthDateResolver;

/**
 * Updates the permanent administrative patient record owned by Reception.
 * Patient's Auditable concern records the changed old/new values.
 */
class UpdatePatientAction
{
    public function __construct(private readonly PatientBirthDateResolver $birthDateResolver) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Patient $patient, array $data): Patient
    {
        $patient->fill($this->birthDateResolver->resolve($data));
        $patient->save();

        return $patient->refresh();
    }
}
