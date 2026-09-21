<?php

namespace App\Http\Requests\Hospitalization;

use App\Http\Requests\StoreCareOrderRequest;

/** ADR-162 — les soins demandés depuis le séjour, validés comme en consultation. */
class StoreStayCareOrderRequest extends StoreCareOrderRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        return $this->stayAllows('care_orders.create');
    }

    public function rules(): array
    {
        // Le patient reste au lit : il n'y a pas de retour en Médecine à décider.
        return [
            ...collect(parent::rules())->except('requires_return_to_medicine')->all(),
            'requires_return_to_medicine' => ['prohibited'],
        ];
    }
}
