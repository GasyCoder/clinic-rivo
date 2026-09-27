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
 * Corrige le libellé d'un rôle. Le code ne change jamais (voir RoleCode) :
 * il est l'identité que les seeders de socle et l'audit désignent.
 */
class UpdateRoleAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array{name: string} $data */
    public function execute(Role $role, array $data, User|CatalogActor $actor): Role
    {
        if ($actor->cannot('roles.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier un rôle.');
        }

        if ($role->isProtected()) {
            throw ValidationException::withMessages([
                'name' => 'Le rôle Super Admin est défini par l’architecture du portail et n’est pas modifiable ici.',
            ]);
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($role, $data, $actorUser) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            $before = $role->name;
            $role->update(['name' => trim($data['name'])]);

            if ($before !== $role->name) {
                $this->auditor->record(
                    'role.update',
                    entity: $role,
                    newValues: ['name' => $role->name],
                    oldValues: ['name' => $before],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            return $role;
        });
    }
}
