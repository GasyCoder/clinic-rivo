<?php

namespace App\Actions\Patient;

use App\Exceptions\DuplicatePatientException;
use App\Models\Patient;
use App\Services\Patient\DuplicatePatientFinder;
use App\Services\Patient\PatientNumberGenerator;

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
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, birth_date: string, sex: string, phone?: string|null, address?: string|null}  $data
     *
     * @throws DuplicatePatientException if a matching patient already
     *         exists and $confirmDuplicate is false — the caller is
     *         expected to show the match and retry with true once a human
     *         has confirmed it is genuinely a different person.
     */
    public function execute(array $data, bool $confirmDuplicate = false): Patient
    {
        if (! $confirmDuplicate) {
            $matches = $this->duplicates->find($data['first_name'], $data['last_name'], $data['birth_date']);

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
