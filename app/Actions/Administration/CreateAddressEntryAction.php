<?php

namespace App\Actions\Administration;

use App\Models\AddressEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAddressEntryAction
{
    /**
     * Adds a reusable address label without silently duplicating a differently
     * cased/accented spelling. An archived entry must be restored explicitly.
     */
    public function execute(string $label, User $actor): AddressEntry
    {
        if ($actor->cannot('address_entries.create')) {
            throw new AuthorizationException('Vous ne pouvez pas ajouter une adresse au référentiel.');
        }

        $label = str($label)->squish()->toString();

        if ($label === '' || mb_strlen($label) > 255) {
            throw ValidationException::withMessages([
                'new_address_label' => 'L’adresse doit contenir entre 1 et 255 caractères.',
            ]);
        }

        return DB::transaction(function () use ($label) {
            $normalized = AddressEntry::normalize($label);
            $existing = AddressEntry::withTrashed()
                ->where('normalized_label', $normalized)
                ->lockForUpdate()
                ->first();

            if ($existing?->trashed()) {
                throw ValidationException::withMessages([
                    'new_address_label' => 'Cette adresse est archivée et doit être restaurée.',
                ]);
            }

            if ($existing) {
                return $existing;
            }

            return AddressEntry::create(['label' => $label, 'active' => true]);
        });
    }
}
