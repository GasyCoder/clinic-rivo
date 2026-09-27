<?php

namespace App\Http\Requests\Maternity;

use App\Http\Requests\StoreLabRequestRequest;

/** ADR-204 — les analyses demandées depuis la Maternité, validées comme en consultation. */
class StoreMaternityLabRequestRequest extends StoreLabRequestRequest
{
    use MaternityRequestAuthorization;

    public function authorize(): bool
    {
        return $this->maternityAllows('laboratory_orders.create');
    }
}
