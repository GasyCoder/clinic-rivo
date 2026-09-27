<?php

namespace App\Services\Administration;

use App\Models\Employee;
use App\Models\User;
use App\Services\Addresses\AddressEntryResolver;

/**
 * L'adresse d'un dossier employé : la règle commune des fiches
 * (AddressEntryResolver), l'adresse déjà portée par l'employé restant
 * choisissable même archivée.
 */
class EmployeeAddressResolver
{
    public function __construct(private readonly AddressEntryResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, User $actor, ?Employee $employee = null): array
    {
        return $this->resolver->resolve($data, $actor, $employee?->address_entry_id);
    }
}
