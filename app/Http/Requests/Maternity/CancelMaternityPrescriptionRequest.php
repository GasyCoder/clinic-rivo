<?php

namespace App\Http\Requests\Maternity;

use App\Enums\PrescriptionStatus;
use App\Http\Requests\CancelMedicinePrescriptionRequest;
use App\Models\Prescription;

/**
 * ADR-205 — retirer une ordonnance de la Maternité : même motif exigé qu'en
 * consultation, et seulement une ordonnance de ce dossier, encore ouvert.
 */
class CancelMaternityPrescriptionRequest extends CancelMedicinePrescriptionRequest
{
    use MaternityRequestAuthorization;

    public function authorize(): bool
    {
        $prescription = $this->route('prescription');
        $record = $this->route('episodeOrientation')?->episode?->maternityRecord;

        return $this->maternityAllows('prescriptions.cancel')
            && $prescription instanceof Prescription
            && $prescription->status === PrescriptionStatus::Active
            && $record !== null
            && ! $record->isFinalized()
            && $prescription->maternity_record_id === $record->getKey();
    }
}
