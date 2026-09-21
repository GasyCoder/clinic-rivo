<?php

namespace App\Http\Requests\Hospitalization;

use App\Enums\PrescriptionStatus;
use App\Http\Requests\CancelMedicinePrescriptionRequest;
use App\Models\Prescription;

/** ADR-162 — retirer une ordonnance du séjour : même motif exigé qu'en consultation. */
class CancelStayPrescriptionRequest extends CancelMedicinePrescriptionRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        $prescription = $this->route('prescription');

        return $this->stayAllows('prescriptions.cancel')
            && $prescription instanceof Prescription
            && $prescription->status === PrescriptionStatus::Active
            && $prescription->hospital_stay_id === $this->route('hospitalStay')->getKey();
    }
}
