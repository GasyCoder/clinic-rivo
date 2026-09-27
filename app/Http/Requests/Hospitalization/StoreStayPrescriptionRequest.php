<?php

namespace App\Http\Requests\Hospitalization;

use App\Http\Requests\StoreMedicinePrescriptionRequest;

/**
 * ADR-162 — l'ordonnance du séjour : mêmes lignes, mêmes règles de dose (ADR-110).
 *
 * ADR-163 — et mêmes propositions (ADR-111) : une ligne retenue d'un protocole
 * ou de la pratique de la clinique porte son origine, que l'action revérifie
 * contre les diagnostics du passage.
 */
class StoreStayPrescriptionRequest extends StoreMedicinePrescriptionRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        return $this->stayAllows('prescriptions.create', 'medicines.view', 'stock.availability.view');
    }
}
