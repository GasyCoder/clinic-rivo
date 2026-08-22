<?php

namespace App\Services\Patient;

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
            $data['declared_age'] = null;
            $data['declared_age_at'] = null;
            unset($data['age']);

            return $data;
        }

        if (! array_key_exists('age', $data) || $data['age'] === null || $data['age'] === '') {
            throw new InvalidArgumentException('A birth date or a declared age is required.');
        }

        // An age is not a date of birth. Keep exactly what the family
        // declared, together with its date, rather than manufacturing a
        // misleading day/month that could later appear on clinical papers.
        $data['birth_date'] = null;
        $data['birth_date_is_approximate'] = true;
        $data['declared_age'] = (int) $data['age'];
        $data['declared_age_at'] = now();
        unset($data['age']);

        return $data;
    }
}
