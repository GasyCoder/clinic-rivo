<?php

namespace App\Actions\Role;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a ROLE's baseline permissions — every account of that role, not one
 * account. Distinct from and additive to the per-account `user_permissions`
 * allow/deny overrides already built (CreateUserAction/UpdateUserAction):
 * this never touches that pivot, and an account's individual overrides keep
 * applying exactly as before on top of whatever baseline results here.
 */
class UpdateRolePermissionsAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param  array<int, int>  $permissionIds */
    public function execute(Role $role, array $permissionIds, User|CatalogActor $actor): Role
    {
        if ($actor->cannot('users.manage')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier les permissions d’un rôle.');
        }

        if ($role->code === 'SUPER_ADMIN') {
            throw ValidationException::withMessages([
                'role' => 'Le socle du rôle Super Admin n’est jamais modifiable ici : il conserve '
                    .'automatiquement toutes les permissions sur le portail central (ADR-025) et aucune '
                    .'sur les sites cliniques (ADR-027).',
            ]);
        }

        // Auditor::record() takes an Authenticatable, never a CatalogActor —
        // for a remote Super Admin this resolves to null, and Auditor falls
        // back to the external_actor_uuid/name already on the request.
        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($role, $permissionIds, $actorUser) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);

            $before = $role->permissions()->pluck('permissions.name')->sort()->values()->all();

            $role->permissions()->sync(collect($permissionIds)->unique()->values());

            $after = $role->permissions()->pluck('permissions.name')->sort()->values()->all();

            $this->auditor->record(
                'role.permissions.update',
                entity: $role,
                newValues: ['permissions' => $after],
                oldValues: ['permissions' => $before],
                module: 'administration',
                actor: $actorUser,
            );

            return $role->load('permissions');
        });
    }
}
