<?php

namespace App\Actions\Medicine;

use App\Enums\PrescriptionStatus;
use App\Models\Consultation;
use App\Models\Prescription;

class CreatePrescriptionAction
{
    /**
     * @param  array<int, array{medication_name: string, dosage?: ?string, frequency?: ?string, duration?: ?string, instructions?: ?string}>  $lines
     */
    public function execute(Consultation $consultation, array $lines): Prescription
    {
        $prescription = $consultation->prescriptions()->create([
            'status' => PrescriptionStatus::Active,
        ]);

        foreach ($lines as $line) {
            $prescription->lines()->create($line);
        }

        return $prescription;
    }
}
