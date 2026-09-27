<?php

namespace App\Http\Requests\Maternity;

use App\Http\Requests\StoreMedicinePrescriptionRequest;

/**
 * ADR-205 — l'ordonnance de la sage-femme : mêmes lignes et mêmes règles de dose
 * qu'en consultation (ADR-110), mêmes droits (`prescriptions.create`, et voir le
 * référentiel et sa disponibilité pour choisir un médicament).
 */
class StoreMaternityPrescriptionRequest extends StoreMedicinePrescriptionRequest
{
    use MaternityRequestAuthorization;

    public function authorize(): bool
    {
        return $this->maternityAllows('prescriptions.create', 'medicines.view', 'stock.availability.view');
    }
}
