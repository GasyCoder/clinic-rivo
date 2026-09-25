<?php

namespace App\Services\Catalog;

use App\Models\RemoteSuperAdmin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CatalogActor
{
    /**
     * ADR-182, amendement du 2026-09-24 — ce que réceptionner une ligne du
     * catalogue du fournisseur emporte : faire entrer le produit au catalogue
     * de la clinique et y rattacher le prix du fournisseur. Rien d'autre —
     * ni famille nouvelle, ni tarif de vente.
     */
    private const RECEPTION_GRANTS = [
        'medicines.create',
        'catalog.items.create',
        'medicine_supplier_offers.create',
        'medicine_supplier_offers.update',
    ];

    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $granted  droits délégués pour ce seul geste
     */
    private function __construct(
        private ?User $user,
        private ?string $externalUuid,
        private ?string $externalName,
        private array $permissions,
        private array $granted = [],
    ) {}

    public static function fromUser(User $user): self
    {
        // ADR-189 — le Super Admin du portail, venu par l'API d'un site (ADR-187) :
        // il n'a pas de compte local. Il agit comme un acteur distant, attribué
        // par son UUID et son nom, avec les seuls droits transmis par le portail.
        if ($user instanceof RemoteSuperAdmin) {
            return new self(null, $user->externalUuid(), $user->name, $user->effectivePermissionNames()->values()->all());
        }

        return new self($user, null, null, []);
    }

    public static function fromRemoteRequest(Request $request): self
    {
        $uuid = trim((string) $request->attributes->get('rivo_actor_uuid'));
        $name = str((string) $request->attributes->get('rivo_actor_name'))->squish()->toString();
        $permissions = $request->attributes->get('rivo_actor_permissions', []);

        if (! Str::isUuid($uuid) || $name === '') {
            throw ValidationException::withMessages([
                'actor' => 'L’identité du Super Administrateur est obligatoire.',
            ]);
        }

        return new self(
            null,
            $uuid,
            mb_substr($name, 0, 150),
            is_array($permissions) ? array_values($permissions) : [],
        );
    }

    /**
     * ADR-182, amendement du 2026-09-24 (décision du propriétaire) —
     * réceptionner suffit : la personne qui a la livraison sous les yeux fait
     * entrer au catalogue de la clinique le produit que le fournisseur a
     * livré, avec le seul droit de réceptionner. Le produit arrive sans prix
     * de vente ; la Pharmacie le fixe à l'entrée en stock (ADR-174).
     *
     * La délégation ne vaut que pour ce geste — elle est demandée par la
     * réception, jamais ailleurs — et un refus individuel l'emporte toujours
     * (ADR-033) : un DENY sur `medicines.create` continue de l'interdire.
     */
    public function receivingDelivery(): self
    {
        if ($this->user === null || $this->user->cannot('goods_receipts.create')) {
            return $this;
        }

        return new self($this->user, $this->externalUuid, $this->externalName, $this->permissions, self::RECEPTION_GRANTS);
    }

    public function can(string $permission): bool
    {
        if (in_array($permission, $this->granted, true) && ! $this->explicitlyDenied($permission)) {
            return true;
        }

        return $this->user
            ? $this->user->can($permission)
            : in_array($permission, $this->permissions, true);
    }

    private function explicitlyDenied(string $permission): bool
    {
        return $this->user !== null && $this->user->permissions()
            ->wherePivot('effect', 'deny')
            ->where('permissions.name', $permission)
            ->exists();
    }

    public function cannot(string $permission): bool
    {
        return ! $this->can($permission);
    }

    public function localUserId(): ?int
    {
        return $this->user?->getKey();
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /** @return array<string, string|null> */
    public function externalAttribution(string $verb): array
    {
        return [
            "external_{$verb}_by_uuid" => $this->externalUuid,
            "external_{$verb}_by_name" => $this->externalName,
        ];
    }
}
