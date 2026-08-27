<?php

namespace App\Actions\Pharmacy;

use App\Models\Prescription;
use Illuminate\Validation\ValidationException;

class SyncInternalDispenseRequestAction
{
    public function execute(Prescription $prescription): void
    {
        $prescription->loadMissing('pharmacyDispense.lines');
        $dispense = $prescription->pharmacyDispense;

        if (! $dispense) {
            return;
        }

        if ($dispense->invoice_id !== null) {
            throw ValidationException::withMessages([
                'prescription' => 'Cette ordonnance a déjà été transmise à la facturation Pharmacie et ne peut plus être modifiée.',
            ]);
        }

        $lines = $prescription->lines()->get()->keyBy('id');

        foreach ($dispense->lines as $dispenseLine) {
            $prescriptionLine = $lines->get($dispenseLine->prescription_line_id);

            if ($prescriptionLine) {
                $dispenseLine->update(['quantity_requested' => $prescriptionLine->quantity]);
            }
        }
    }
}
