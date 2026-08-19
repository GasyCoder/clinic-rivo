<?php

namespace App\Actions\Patient;

use App\Exceptions\DuplicatePatientException;
use App\Models\Patient;
use App\Services\Patient\DuplicatePatientFinder;
use App\Services\Patient\PatientNumberGenerator;
use Illuminate\Support\Carbon;

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
     * @param  array{first_name?: ?string, last_name: string, birth_date?: ?string, age?: ?int, sex: string, civility?: ?string, phone?: ?string, email?: ?string, address?: ?string, emergency_contact_name?: ?string, emergency_contact_phone?: ?string, emergency_contact_relationship?: ?string, emergency_contact_email?: ?string}  $data
     *         Exactly one of `birth_date`/`age` is expected — validation is
     *         the caller's job (see StoreArrivalRequest); this only
     *         normalizes whichever was given.
     *
     * @throws DuplicatePatientException if a matching patient already
     *         exists and $confirmDuplicate is false — the caller is
     *         expected to show the match and retry with true once a human
     *         has confirmed it is genuinely a different person.
     */
    public function execute(array $data, bool $confirmDuplicate = false): Patient
    {
        $data = $this->resolveBirthDate($data);

        if (! $confirmDuplicate) {
            $matches = $this->duplicates->find($data['first_name'] ?? null, $data['last_name'], $data['birth_date']);

            if ($matches->isNotEmpty()) {
                throw new DuplicatePatientException($matches);
            }
        }

        return Patient::create([
            ...$data,
            'patient_number' => $this->numbers->next(),
        ]);
    }

    /**
     * A patient who only knows their age gets a computed birth_date (so
     * every date-based feature — anti-doublon, sorting, future age-based
     * clinical rules — keeps working) flagged as approximate rather than
     * silently presented as exact.
     */
    private function resolveBirthDate(array $data): array
    {
        if (! empty($data['birth_date'])) {
            $data['birth_date_is_approximate'] = false;
            unset($data['age']);

            return $data;
        }

        $data['birth_date'] = Carbon::now()->subYears((int) $data['age'])->toDateString();
        $data['birth_date_is_approximate'] = true;
        unset($data['age']);

        return $data;
    }
}
