<?php

namespace App\Services\Patient;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Normalizes the two accepted ways of recording a patient's birth:
 * an exact date, or a declared age when the date is unknown.
 */
class PatientBirthDateResolver
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        if (! empty($data['birth_date'])) {
            $data['birth_date_is_approximate'] = false;
            unset($data['age']);

            return $data;
        }

        if (! array_key_exists('age', $data) || $data['age'] === null || $data['age'] === '') {
            throw new InvalidArgumentException('A birth date or a declared age is required.');
        }

        $data['birth_date'] = Carbon::today()->subYears((int) $data['age'])->toDateString();
        $data['birth_date_is_approximate'] = true;
        unset($data['age']);

        return $data;
    }
}
