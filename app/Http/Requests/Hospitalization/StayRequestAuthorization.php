<?php

namespace App\Http\Requests\Hospitalization;

use App\Models\HospitalStay;

/**
 * ADR-162 — une demande écrite depuis le séjour : le patient doit être au lit
 * et le compte doit détenir le droit de la demande. Le reste de la validation
 * est celui de la même demande faite en consultation, dont la FormRequest est
 * héritée plutôt que recopiée.
 */
trait StayRequestAuthorization
{
    protected function stayAllows(string ...$abilities): bool
    {
        $stay = $this->route('hospitalStay');

        if (! $stay instanceof HospitalStay || ! $stay->isActive()) {
            return false;
        }

        foreach ($abilities as $ability) {
            if (! $this->user()?->can($ability)) {
                return false;
            }
        }

        return true;
    }
}
