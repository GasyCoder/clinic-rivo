<?php

namespace App\Services\Administration;

use App\Actions\Administration\CreateAddressEntryAction;
use App\Models\AddressEntry;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class EmployeeAddressResolver
{
    public function __construct(private readonly CreateAddressEntryAction $createAddressEntry) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, User $actor, ?Employee $employee = null): array
    {
        if (! array_key_exists('address_entry_uuid', $data)
            && ! array_key_exists('new_address_label', $data)) {
            return $data;
        }

        $address = null;

        if (! empty($data['address_entry_uuid'])) {
            $address = AddressEntry::withTrashed()
                ->where('uuid', $data['address_entry_uuid'])
                ->first();

            $keepsHistoricalAddress = $address
                && $employee?->address_entry_id === $address->getKey();

            if (! $address || ((! $address->active || $address->trashed()) && ! $keepsHistoricalAddress)) {
                throw ValidationException::withMessages([
                    'address_entry_uuid' => 'Cette adresse n’est plus disponible.',
                ]);
            }
        } elseif (! empty($data['new_address_label'])) {
            $address = $this->createAddressEntry->execute($data['new_address_label'], $actor);
        }

        $data['address_entry_id'] = $address?->getKey();
        $data['address'] = $address?->label;
        unset($data['address_entry_uuid'], $data['new_address_label']);

        return $data;
    }
}
