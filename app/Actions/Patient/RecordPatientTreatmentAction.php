<?php

namespace App\Actions\Patient;

use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\User;

class RecordPatientTreatmentAction
{
    /** @param array{medication_name: string, dosage?: ?string, frequency?: ?string, duration?: ?string, notes?: ?string} $data */
    public function execute(Patient $patient, array $data, User $actor): PatientTreatment
    {
        return $patient->treatments()->create([
            'medication_name' => trim($data['medication_name']),
            'dosage' => $this->nullableText($data['dosage'] ?? null),
            'frequency' => $this->nullableText($data['frequency'] ?? null),
            'duration' => $this->nullableText($data['duration'] ?? null),
            'notes' => $this->nullableText($data['notes'] ?? null),
            'active' => true,
            'recorded_by' => $actor->getKey(),
        ]);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
