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
 * Retire une permission du catalogue — physiquement, et seulement si elle
 * n'a jamais rien protégé ni rien accordé (ADR-101).
 *
 * Le Soft Delete n'a pas été retenu ici, contrairement aux rôles : une
 * permission archivée resterait citée par `role_permissions` et
 * `user_permissions` sans apparaître nulle part, et le socle d'un rôle
 * deviendrait illisible. L'exception est donc étroite, dans l'esprit de
 * l'ADR-062 pour un compte jamais utilisé :
 *
 *     accordée à un rôle       -> refus, le socle la désigne
 *     exception sur un compte  -> refus, une décision la désigne
 *     vérifiée par le code     -> refus, la retirer ouvrirait un accès
 *
 * Le dernier cas est le plus important : supprimer une permission que
 * `can:` vérifie ne retire pas le contrôle, il le rend impossible à
 * satisfaire — l'écran devient inaccessible à tout le monde, sans message.
 */
class DeletePermissionAction
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly PermissionUsageScanner $usage,
    ) {}

    public function execute(Permission $permission, User|CatalogActor $actor): void
    {
        if ($actor->cannot('permissions.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas supprimer une permission.');
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        DB::transaction(function () use ($permission, $actorUser) {
            $permission = Permission::query()->lockForUpdate()->findOrFail($permission->id);
            $roles = $permission->roles()->count();
            $accounts = $permission->users()->count();

            if ($roles > 0 || $accounts > 0) {
                throw ValidationException::withMessages([
                    'permission' => trim(
                        ($roles > 0 ? "{$roles} rôle(s) l’accordent encore. " : '')
                        .($accounts > 0 ? "{$accounts} compte(s) portent une exception dessus. " : '')
                        .'Retirez-la d’abord de ces socles et de ces comptes.',
                    ),
                ]);
            }

            if (isset($this->usage->usedNames()[$permission->name])) {
                throw ValidationException::withMessages([
                    'permission' => "L’application vérifie « {$permission->name} » quelque part. "
                        .'La supprimer ne retirerait pas le contrôle : elle rendrait l’écran concerné inaccessible à tout le monde.',
                ]);
            }

            $name = $permission->name;
            $label = $permission->label;
            $permission->delete();

            $this->auditor->record(
                'permission.delete',
                entity: $permission,
                oldValues: ['name' => $name, 'label' => $label],
                module: 'administration',
                actor: $actorUser,
            );

            $this->usage->forget();
        });
    }
}
