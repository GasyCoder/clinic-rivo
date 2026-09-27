<?php

namespace App\Actions\Partners;

use App\Enums\PartnerCategory;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\Addresses\AddressEntryResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-211 — créer ou corriger la fiche d'un partenaire.
 *
 * Deux fiches ne portent jamais le même nom, archives comprises : un partenaire
 * archivé se restaure, il ne se recrée pas (même règle que les mutuelles,
 * ADR-045). Une fiche médicale reliée à un dossier patient reste médicale : elle
 * désigne la personne de ce dossier. Chaque écriture est auditée (Auditable),
 * au nom du compte du site ou du Super Admin distant (ADR-187).
 */
class SavePartnerAction
{
    private const FIELDS = [
        'category', 'name', 'last_name', 'first_name', 'profession', 'profession_detail',
        'sex', 'birth_date', 'phone', 'email', 'address_entry_id', 'address', 'notes', 'active',
    ];

    public function __construct(private readonly AddressEntryResolver $addresses) {}

    /** @param array<string, mixed> $data */
    public function execute(?PartnerOrganization $partner, array $data, User $actor): PartnerOrganization
    {
        $permission = $partner ? 'partner_organizations.update' : 'partner_organizations.create';

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Vous ne pouvez pas '.($partner ? 'modifier' : 'ajouter').' un partenaire.');
        }

        return DB::transaction(function () use ($partner, $data, $actor): PartnerOrganization {
            if ($partner) {
                $partner = PartnerOrganization::query()->lockForUpdate()->findOrFail($partner->getKey());
            }

            // L'adresse du référentiel du site, ou une nouvelle entrée qui le rejoint.
            $data = $this->addresses->resolve($data, $actor, $partner?->address_entry_id);

            $category = PartnerCategory::from($data['category']);

            if ($partner?->patient_id !== null && $category !== PartnerCategory::Medical) {
                throw ValidationException::withMessages([
                    'category' => 'Cette fiche est reliée au dossier patient de la même personne : elle reste un partenaire médical.',
                ]);
            }

            $attributes = Arr::only($data, self::FIELDS);
            $partner ??= new PartnerOrganization(['active' => true]);
            $partner->fill($attributes);

            if ($category === PartnerCategory::Medical) {
                $partner->name = trim(($data['last_name'] ?? '').' '.($data['first_name'] ?? ''));
            }

            $this->ensureUniqueName($partner, $category);
            $partner->save();

            return $partner;
        });
    }

    private function ensureUniqueName(PartnerOrganization $partner, PartnerCategory $category): void
    {
        $normalized = PartnerOrganization::normalize((string) $partner->name);

        $existing = PartnerOrganization::withTrashed()
            ->where('normalized_name', $normalized)
            ->when($partner->exists, fn ($query) => $query->whereKeyNot($partner->getKey()))
            ->first();

        if (! $existing) {
            return;
        }

        throw ValidationException::withMessages([
            $category === PartnerCategory::Medical ? 'last_name' : 'name' => $existing->trashed()
                ? "Un partenaire archivé s’appelle déjà « {$existing->name} » : restaurez-le depuis le filtre « Archivés »."
                : "Un partenaire s’appelle déjà « {$existing->name} ».",
        ]);
    }
}
