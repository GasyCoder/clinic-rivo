<?php

namespace App\Services\Addresses;

use App\Actions\Administration\CreateAddressEntryAction;
use App\Models\AddressEntry;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Le champ « Adresse » des fiches (employé, partenaire) : une entrée du
 * référentiel d'adresses du site (ADR-042), ou une nouvelle entrée ajoutée à
 * ce référentiel — jamais un texte libre à côté de lui.
 *
 * Reçoit `address_entry_uuid` ou `new_address_label` et rend `address_entry_id`
 * et `address`, l'instantané du libellé que les écrans et les documents lisent.
 * Omettre les deux clés laisse l'adresse telle qu'elle est ; les envoyer vides
 * l'efface. Une adresse archivée ne se choisit plus, sauf si la fiche la porte
 * déjà : on ne retire pas en silence l'adresse d'un dossier existant.
 */
class AddressEntryResolver
{
    public function __construct(private readonly CreateAddressEntryAction $createAddressEntry) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, User $actor, ?int $currentAddressEntryId = null): array
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
                && $currentAddressEntryId !== null
                && $currentAddressEntryId === $address->getKey();

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
