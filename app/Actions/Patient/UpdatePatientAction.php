<?php

namespace App\Actions\Patient;

use App\Actions\Administration\CreateAddressEntryAction;
use App\Models\AddressEntry;
use App\Models\Patient;
use App\Models\User;
use App\Services\Patient\PatientBirthDateResolver;
use Illuminate\Validation\ValidationException;

/**
 * Updates the permanent administrative patient record owned by Reception.
 * Patient's Auditable concern records the changed old/new values.
 */
class UpdatePatientAction
{
    public function __construct(
        private readonly PatientBirthDateResolver $birthDateResolver,
        private readonly CreateAddressEntryAction $createAddressEntry,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Patient $patient, array $data, ?User $actor = null): Patient
    {
        if (array_key_exists('address_entry_uuid', $data)
            || array_key_exists('new_address_label', $data)) {
            $addressEntry = null;

            if (! empty($data['address_entry_uuid'])) {
                $addressEntry = AddressEntry::query()
                    ->where('uuid', $data['address_entry_uuid'])
                    ->where('active', true)
                    ->first();

                if (! $addressEntry) {
                    throw ValidationException::withMessages([
                        'address_entry_uuid' => 'Cette adresse n’est plus disponible.',
                    ]);
                }
            } elseif (! empty($data['new_address_label'])) {
                if (! $actor) {
                    throw new \LogicException('Un utilisateur est requis pour créer une adresse contrôlée.');
                }

                $addressEntry = $this->createAddressEntry->execute(
                    $data['new_address_label'],
                    $actor,
                );
            }

            $data['address_entry_id'] = $addressEntry?->id;
            $data['address'] = $addressEntry?->label;
            unset($data['address_entry_uuid'], $data['new_address_label']);
        }

        // Only a caller that submits a raw free-text 'address' WITHOUT also
        // resolving 'address_entry_id' reaches this fallback (e.g. a future
        // free-text-only integration). When address_entry_id is already
        // present — set by the block above, or pre-resolved by a caller
        // such as RegisterArrivalAction::patientDataFromEmployee() — it must
        // win; comparing its label against the patient's *previous* link
        // would otherwise null out a deliberate address change.
        if (array_key_exists('address', $data)
            && ! array_key_exists('address_entry_id', $data)
            && $patient->address_entry_id !== null) {
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
