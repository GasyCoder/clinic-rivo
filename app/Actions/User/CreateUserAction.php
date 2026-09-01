<?php

namespace App\Actions\User;

use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateUserAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
        private readonly SyncProfessionalProfilePermissionsAction $syncProfilePermissions,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User|CatalogActor $actor): User
    {
        if ($actor->cannot('users.create') || $actor->cannot('roles.assign')) {
            throw new AuthorizationException('Vous ne pouvez pas créer un utilisateur ou lui attribuer un rôle.');
        }

        // Auditor::record() takes an Authenticatable, never a CatalogActor —
        // for a remote Super Admin this resolves to null, and Auditor falls
        // back to the external_actor_uuid/name already on the request.
        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($data, $actor, $actorUser) {
            $role = Role::query()->lockForUpdate()->findOrFail($data['role_id']);
            $profile = filled($data['professional_profile_id'] ?? null)
                ? ProfessionalProfile::query()->lockForUpdate()->find($data['professional_profile_id'])
                : null;
            $this->guard->assertCanAssignRole($actor, $role);
            $this->guard->assertProfileMatchesRole($role, $profile);

            $overrides = $data['permission_overrides'] ?? [];
            $syncRecommended = (bool) ($data['sync_profile_permissions'] ?? false);

            if (($overrides !== [] || $syncRecommended) && ! $actor->can('permissions.assign')) {
                throw ValidationException::withMessages([
                    'permission_overrides' => "Vous n'êtes pas autorisé à attribuer des permissions individuelles.",
                ]);
            }

            // No password supplied: the account is provisioned by invitation
            // instead — a random, never-communicated password satisfies the
            // column, and the real one is set by the user themselves through
            // the same reset-password link and page as "forgot password".
            $invited = blank($data['password'] ?? null);

            $user = new User([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $invited ? Str::password(40) : $data['password'],
                'role_id' => $role->id,
                'professional_profile_id' => $profile?->id,
                'email_verified_at' => now(),
            ]);
            $user->forceFill(['active' => true])->save();

            $profileSync = $this->syncProfilePermissions->execute(
                $user,
                null,
                $profile,
                array_key_exists('permission_overrides', $data) ? $overrides : null,
                $syncRecommended,
            );

            if ($invited) {
                Password::sendResetLink(['email' => $user->email]);
            }

            $this->auditor->record(
                'user.create',
                entity: $user,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role->code,
                    'professional_profile' => $profile?->code,
                    'active' => true,
                    'invited' => $invited,
                ],
                module: 'administration',
                actor: $actorUser,
            );

            if ($invited) {
                $this->auditor->record(
                    'user.invite.sent',
                    entity: $user,
                    newValues: ['email' => $user->email],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            $this->auditor->record(
                'user.role.assign',
                entity: $user,
                newValues: ['role' => $role->code],
                module: 'administration',
                actor: $actorUser,
            );

            if ($profile) {
                $this->auditor->record(
                    'user.profile.assign',
                    entity: $user,
                    newValues: ['professional_profile' => $profile->code],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            $user->load('permissions');
            $assignedOverrides = $this->auditOverrides($user);

            if ($assignedOverrides !== []) {
                $this->auditor->record(
                    'user.permissions.assign',
                    entity: $user,
                    newValues: ['overrides' => $assignedOverrides],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            if ($syncRecommended) {
                $this->auditor->record(
                    'user.profile.permissions.sync',
                    entity: $user,
                    newValues: [
                        'old_profile' => null,
                        'new_profile' => $profile?->code,
                        ...$profileSync,
                    ],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            return $user->load(['role', 'professionalProfile', 'permissions']);
        });
    }

    private function auditOverrides(User $user): array
    {
        return $user->permissions->map(fn (Permission $permission) => [
            'permission' => $permission->name,
            'effect' => $permission->pivot->effect,
            'source' => $permission->pivot->source,
            'source_profile_id' => $permission->pivot->source_profile_id,
        ])->sortBy('permission')->values()->all();
    }
}
