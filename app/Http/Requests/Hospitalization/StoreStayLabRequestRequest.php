<?php

namespace App\Http\Requests\Hospitalization;

use App\Http\Requests\StoreLabRequestRequest;

/** ADR-162 — les analyses demandés depuis le séjour, validés comme en consultation. */
class StoreStayLabRequestRequest extends StoreLabRequestRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        return $this->stayAllows('laboratory_orders.create');
    }
}
