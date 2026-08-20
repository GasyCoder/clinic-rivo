<?php

namespace App\Actions\Medicine;

use App\Models\Prescription;

class CancelPrescriptionAction
{
    public function execute(Prescription $prescription, string $reason): Prescription
    {
        $prescription->cancel($reason);

        return $prescription;
    }
}
