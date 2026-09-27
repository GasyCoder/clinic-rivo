<?php

namespace App\Actions\Permission;

use App\Models\Permission;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\PermissionUsageScanner;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajoute une permission au catalogue d'un site (ADR-101).
 *
 * Elle est immédiatement attribuable à un rôle ou à un compte. Elle
 * n'**ouvre** en revanche rien tant qu'aucune route, Policy ou écran ne la
 * vérifie : l'interface l'annonce comme « pas encore vérifiée par
 * l'application », un état calculé depuis le code par
 * `PermissionUsageScanner` et jamais déclaré à la main.
 */
class CreatePermissionAction
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly PermissionUsageScanner $usage,
    ) {}

    /** @param array{name: string, label: string} $data */
    public function execute(array $data, User|CatalogActor $actor): Permission
    {
        if ($actor->cannot('permissions.create')) {
            throw new AuthorizationException('Vous ne pouvez pas créer une permission.');
        }

        $name = PermissionName::normalize($data['name']);
        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($name, $data, $actorUser) {
            if (Permission::query()->where('name', $name)->exists()) {
                throw ValidationException::withMessages([
                    'name' => "La permission « {$name} » existe déjà dans ce catalogue.",
                ]);
            }

            $permission = Permission::query()->create([
                'name' => $name,
                'label' => trim($data['label']),
            ]);

            $this->auditor->record(
                'permission.create',
                entity: $permission,
                newValues: ['name' => $permission->name, 'label' => $permission->label],
                module: 'administration',
                actor: $actorUser,
            );

            // Le nouveau nom n'est vérifié nulle part : le balayage doit le
            // dire dès le prochain affichage, pas au bout de son cache.
            $this->usage->forget();

            return $permission;
        });
    }
}
