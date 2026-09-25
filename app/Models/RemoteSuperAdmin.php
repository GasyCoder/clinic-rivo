<?php

namespace App\Models;

use Illuminate\Support\Collection;
use LogicException;

/**
 * ADR-182 — le Super Administrateur du portail, tel qu'un site le voit
 * pendant un seul appel d'API.
 *
 * Le portail gère les Ressources humaines d'un site par son API (CDC §2,
 * §18) : les écrans, contrôleurs, règles de droits et actions RH du site sont
 * réutilisés tels quels. Ils attendent un `User` ; celui-ci en est un, mais il
 * n'existe que le temps de la requête :
 *
 * - il n'est **jamais enregistré** — toute écriture le refuse ;
 * - il n'a **pas d'identifiant local** : une colonne « auteur » reste vide, et
 *   l'audit enregistre l'UUID et le nom du Super Admin (Auditor) ;
 * - ses droits sont **ceux que le portail a transmis** pour cet appel, jamais
 *   un rôle : le site les revérifie à chaque route, règle et action (ADR-007).
 *
 * Il ne se crée que derrière `rivo.site-api`, c'est-à-dire pour un appel
 * authentifié par le jeton du site (AuthenticateRivoSiteApi).
 */
class RemoteSuperAdmin extends User
{
    /**
     * Sans ces deux précisions, Eloquent devinerait une table
     * `remote_super_admins` et une clé `remote_super_admin_id` : la moindre
     * relation lue sur lui (exceptions de droits, auteur) interrogerait une
     * colonne qui n'existe pas.
     */
    protected $table = 'users';

    public function getForeignKey(): string
    {
        return 'user_id';
    }

    /** @var array<int, string> */
    private array $grantedPermissions = [];

    /** @param array<int, string> $permissions */
    public static function fromPortal(string $uuid, string $name, array $permissions, ?int $roleId): self
    {
        $actor = new self;
        $actor->forceFill([
            'uuid' => $uuid,
            'name' => $name,
            'email' => $uuid.'@portail.rivo.invalid',
            // La définition technique du rôle SUPER_ADMIN existe sur chaque
            // site (ADR-027) ; sans rôle, le Gate refuserait tout.
            'role_id' => $roleId,
            'active' => true,
            'deactivated_at' => null,
        ]);
        $actor->exists = false;
        $actor->grantedPermissions = array_values(array_unique($permissions));

        return $actor;
    }

    public function effectivePermissionNames(): Collection
    {
        return collect($this->grantedPermissions);
    }

    public function getAuthIdentifier(): mixed
    {
        return null;
    }

    public function getKey(): mixed
    {
        return null;
    }

    public function externalUuid(): string
    {
        return (string) $this->getAttribute('uuid');
    }

    public function externalName(): string
    {
        return (string) $this->getAttribute('name');
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        throw new LogicException('Le Super Administrateur distant n’est jamais enregistré dans la base d’un site.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Le Super Administrateur distant n’est jamais enregistré dans la base d’un site.');
    }
}
