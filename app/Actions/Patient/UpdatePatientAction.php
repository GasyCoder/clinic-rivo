<?php

namespace App\Actions\Patient;

use App\Models\AddressEntry;
use App\Models\Patient;
use App\Services\Patient\PatientBirthDateResolver;

/**
 * Updates the permanent administrative patient record owned by Reception.
 * Patient's Auditable concern records the changed old/new values.
 */
class UpdatePatientAction
{
    public function __construct(private readonly PatientBirthDateResolver $birthDateResolver) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Patient $patient, array $data): Patient
    {
        // The legacy edit screen still submits a free-text address. Do not
        // keep a stale reference to a different entry from the controlled
        // address directory when that text is changed or cleared.
        if (array_key_exists('address', $data) && $patient->address_entry_id !== null) {
            $linkedLabel = $patient->addressEntry()->value('label');
            $submitted = (string) ($data['address'] ?? '');

            if ($linkedLabel === null
                || AddressEntry::normalize($submitted) !== AddressEntry::normalize($linkedLabel)) {
                $data['address_entry_id'] = null;
            }
        }

        $patient->fill($this->birthDateResolver->resolve($data));
        $patient->save();

        return $patient->refresh();
    }
}
