<?php

namespace App\Services\Administration;

use App\Enums\PatientCivility;
use App\Enums\PatientSex;

final class EmployeeIdentityNormalizer
{
    /**
     * An employee is an adult administrative identity. Civility is therefore
     * derived from the canonical sex field instead of being entered twice.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $sex = $data['sex'] ?? null;
        $sex = $sex instanceof PatientSex ? $sex : PatientSex::tryFrom((string) $sex);

        if ($sex) {
            $data['civility'] = match ($sex) {
                PatientSex::Male => PatientCivility::Mr->value,
                PatientSex::Female => PatientCivility::Mrs->value,
            };
        }

        return $data;
    }
}
