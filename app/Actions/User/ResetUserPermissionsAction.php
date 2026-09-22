<?php

namespace App\Actions\User;

use App\Models\Permission;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Remove every individual permission so the account inherits its role only. */
class ResetUserPermissionsAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
    ) {}

    public function execute(User $user, User|CatalogActor $actor): User
    {
        if ($actor->cannot('permissions.assign')) {
            throw new AuthorizationException('Vous n’êtes pas autorisé à réinitialiser les permissions individuelles.');
        }

        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($user, $actor, $actorUser) {
            $user = User::query()->with('permissions')->lockForUpdate()->findOrFail($user->id);

            $this->guard->assertCanManageTarget($actor, $user);
            $this->guard->assertCanChangeOwnPermissions($actor, $user);

            $before = $this->snapshot($user);

            // This is intentionally broader than the ordinary editor, which
            // replaces MANUAL rows only. A reset means pure role inheritance:
            // previous PROFILE recommendations are removed too and are never
            // silently re-applied (ADR-033).
            $user->permissions()->detach();
            $user->load('permissions');

            $this->auditor->record(
                'user.permissions.reset',
                entity: $user,
                newValues: [
                    'overrides' => [],
                    'inherits_role_only' => true,
                    'role' => $user->role?->code,
                ],
                oldValues: ['overrides' => $before],
                module: 'administration',
                actor: $actorUser,
            );

            return $user->load(['role', 'professionalProfile', 'permissions']);
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function snapshot(User $user): array
    {
        return $user->permissions->map(fn (Permission $permission) => [
            'permission' => $permission->name,
            'effect' => $permission->pivot->effect,
            'source' => $permission->pivot->source,
            'source_profile_id' => $permission->pivot->source_profile_id,
        ])->sortBy('permission')->values()->all();
    }
}
