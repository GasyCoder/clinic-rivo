<?php

namespace App\Actions\Patient;

use App\Exceptions\DuplicatePatientException;
use App\Models\Patient;
use App\Services\Patient\DuplicatePatientFinder;
use App\Services\Patient\PatientBirthDateResolver;
use App\Services\Patient\PatientNumberGenerator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only place a Patient gets created — Réception's future controller
 * calls this rather than `Patient::create()` directly, so the anti-doublon
 * check and patient_number generation can never be bypassed.
 */
class CreatePatientAction
{
    public function __construct(
        private readonly PatientNumberGenerator $numbers,
        private readonly DuplicatePatientFinder $duplicates,
        private readonly PatientBirthDateResolver $birthDateResolver,
    ) {}

    /**
     * Exactly one of birth_date/age is expected in the validated data.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DuplicatePatientException when an unconfirmed match exists
     */
    public function execute(array $data, bool $confirmDuplicate = false): Patient
    {
        $data['first_name'] = isset($data['first_name'])
            ? Str::squish((string) $data['first_name']) ?: null
            : null;
        $data['last_name'] = Str::squish((string) ($data['last_name'] ?? ''));

        if ($data['last_name'] === '') {
            throw new InvalidArgumentException('The patient last name is required.');
        }

        $data = $this->birthDateResolver->resolve($data);

        if (! $confirmDuplicate) {
            $matches = $this->duplicates->find(
                $data['first_name'] ?? null,
                $data['last_name'],
                $data['birth_date'] ?? null,
                $data['declared_age'] ?? null,
            );

            if ($matches->isNotEmpty()) {
                throw new DuplicatePatientException($matches);
            }
        }

        return Patient::create([
            ...$data,
            'patient_number' => $this->numbers->next(),
        ]);
    }
}
