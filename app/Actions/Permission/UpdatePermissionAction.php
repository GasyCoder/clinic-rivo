<?php

namespace App\Actions\Permission;

use App\Models\Permission;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Corrige le libellé d'une permission — jamais son nom (voir PermissionName).
 *
 * Le libellé est ce que lit la personne qui coche la case ; c'est donc la
 * seule partie qui doit pouvoir être reformulée quand elle se révèle
 * ambiguë.
 */
class UpdatePermissionAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array{label: string} $data */
    public function execute(Permission $permission, array $data, User|CatalogActor $actor): Permission
    {
        if ($actor->cannot('permissions.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier une permission.');
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($permission, $data, $actorUser) {
            $permission = Permission::query()->lockForUpdate()->findOrFail($permission->id);
            $before = $permission->label;
            $permission->update(['label' => trim($data['label'])]);

            if ($before !== $permission->label) {
                $this->auditor->record(
                    'permission.update',
                    entity: $permission,
                    newValues: ['label' => $permission->label],
                    oldValues: ['label' => $before],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            return $permission;
        });
    }
}
