<?php

namespace App\Http\Requests\Maternity;

use App\Http\Requests\StoreImagingRequestRequest;

/** ADR-204 — l'imagerie demandée depuis la Maternité, validée comme en consultation. */
class StoreMaternityImagingRequestRequest extends StoreImagingRequestRequest
{
    use MaternityRequestAuthorization;

    public function authorize(): bool
    {
        return $this->maternityAllows('imaging_orders.create');
    }
}
