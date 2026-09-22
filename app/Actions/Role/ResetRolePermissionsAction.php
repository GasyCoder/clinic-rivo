<?php

namespace App\Actions\Role;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Restore a built-in role to the baseline shipped by the application. */
class ResetRolePermissionsAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(Role $role, User|CatalogActor $actor): Role
    {
        if ($actor->cannot('users.manage')) {
            throw new AuthorizationException('Vous ne pouvez pas réinitialiser les permissions d’un rôle.');
        }

        if ($role->isProtected()) {
            throw ValidationException::withMessages([
                'role' => 'Le socle du rôle Super Admin est géré automatiquement et ne peut pas être réinitialisé ici.',
            ]);
        }

        $defaultIds = RolePermissionSeeder::defaultPermissionIds($role->code);

        if ($defaultIds === null) {
            throw ValidationException::withMessages([
                'role' => 'Ce rôle personnalisé ne possède pas de socle système à restaurer. Réglez son socle manuellement.',
            ]);
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($role, $defaultIds, $actorUser) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            $before = $role->permissions()->pluck('permissions.name')->sort()->values()->all();

            $role->permissions()->sync($defaultIds);

            $after = $role->permissions()->pluck('permissions.name')->sort()->values()->all();

            $this->auditor->record(
                'role.permissions.reset',
                entity: $role,
                newValues: ['permissions' => $after, 'source' => RolePermissionSeeder::class],
                oldValues: ['permissions' => $before],
                module: 'administration',
                actor: $actorUser,
            );

            return $role->load('permissions');
        });
    }
}
