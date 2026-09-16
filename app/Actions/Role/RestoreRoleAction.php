<?php

namespace App\Actions\Role;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ramène un rôle archivé. Son socle et son code reviennent tels quels : un
 * rôle archivé garde ses permissions, c'est ce qui rend l'archivage réversible
 * sans reconfiguration.
 */
class RestoreRoleAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(Role $role, User|CatalogActor $actor): Role
    {
        if ($actor->cannot('roles.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer un rôle.');
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($role, $actorUser) {
            $role = Role::query()->withTrashed()->lockForUpdate()->findOrFail($role->id);

            if (! $role->trashed()) {
                return $role;
            }

            $role->restore();
            $role->forceFill(['deleted_by' => null, 'delete_reason' => null])->save();

            $this->auditor->record(
                'role.restore',
                entity: $role,
                newValues: ['archived' => false],
                oldValues: ['archived' => true],
                module: 'administration',
                actor: $actorUser,
            );

            return $role->load('permissions');
        });
    }
}
