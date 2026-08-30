<?php

namespace App\Actions\Administration;

use App\Models\AddressEntry;
use App\Models\User;
use App\Services\Administration\AddressEntryManager;
use Illuminate\Auth\Access\AuthorizationException;

class CreateAddressEntryAction
{
    public function __construct(private readonly AddressEntryManager $manager) {}

    /**
     * Adds a reusable address label without silently duplicating a differently
     * cased/accented spelling. An archived entry must be restored explicitly.
     */
    public function execute(string $label, User $actor): AddressEntry
    {
        if ($actor->cannot('address_entries.create')) {
            throw new AuthorizationException('Vous ne pouvez pas ajouter une adresse au référentiel.');
        }

        return $this->manager->create($label, 'new_address_label');
    }
}
