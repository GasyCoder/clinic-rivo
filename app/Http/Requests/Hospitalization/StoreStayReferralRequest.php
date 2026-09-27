<?php

namespace App\Http\Requests\Hospitalization;

use App\Http\Requests\Medicine\StoreMedicalReferralRequest;

/** ADR-162 — le transfert demandé depuis le séjour, validé comme en consultation. */
class StoreStayReferralRequest extends StoreMedicalReferralRequest
{
    use StayRequestAuthorization;

    public function authorize(): bool
    {
        return $this->stayAllows('transfer.request');
    }
}
