<?php

namespace App\Http\Requests\Hospitalization;

use App\Http\Requests\StoreImagingRequestRequest;

/** ADR-162 — l'imagerie demandés depuis le séjour, validés comme en consultation. */
class StoreStayImagingRequestRequest extends StoreImagingRequestRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        return $this->stayAllows('imaging_orders.create');
    }
}
