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
use Illuminate\Validation\ValidationException;

class UpdateUserAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $user, array $data, User|CatalogActor $actor): User
    {
        if ($actor->cannot('users.update') || $actor->cannot('roles.assign')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier cet utilisateur ou son rôle.');
        }

        // Auditor::record() takes an Authenticatable, never a CatalogActor —
        // for a remote Super Admin this resolves to null, and Auditor falls
        // back to the external_actor_uuid/name already on the request.
        $actorUser = $actor instanceof User ? $actor : $actor->user();

        return DB::transaction(function () use ($user, $data, $actor, $actorUser) {
            $user = User::query()->with(['role', 'professionalProfile', 'permissions'])->lockForUpdate()->findOrFail($user->id);
            $role = Role::query()->lockForUpdate()->findOrFail($data['role_id']);
            $profile = filled($data['professional_profile_id'] ?? null)
                ? ProfessionalProfile::query()->lockForUpdate()->find($data['professional_profile_id'])
                : null;

            $this->guard->assertCanManageTarget($actor, $user);
            $this->guard->assertCanAssignRole($actor, $role);
            $this->guard->assertProfileMatchesRole($role, $profile);
            $this->guard->assertCanChangeOwnRole($actor, $user, $role);
            $this->guard->assertCanChangeOwnProfile($actor, $user, $profile);
            $this->guard->assertLastActiveSuperAdminPreserved($user, $role);

            $hasOverrides = array_key_exists('permission_overrides', $data);

            if ($hasOverrides) {
                if (! $actor->can('permissions.assign')) {
                    throw ValidationException::withMessages([
                        'permission_overrides' => "Vous n'êtes pas autorisé à attribuer des permissions individuelles.",
                    ]);
                }

                $this->guard->assertCanChangeOwnPermissions($actor, $user);
            }

            $oldValues = [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->code,
                'professional_profile' => $user->professionalProfile?->code,
            ];
            $oldOverrides = $user->permissions->map(fn ($permission) => [
                'permission' => $permission->name,
                'effect' => $permission->pivot->effect,
            ])->sortBy('permission')->values()->all();
            $passwordChanged = filled($data['password'] ?? null);

            $user->fill([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'role_id' => $role->id,
                'professional_profile_id' => $profile?->id,
            ]);

            if ($passwordChanged) {
                $user->password = $data['password'];
                $user->remember_token = null;
            }

            $user->save();

            if ($hasOverrides) {
                $user->permissions()->sync($this->pivotValues($data['permission_overrides']));
            }

            $newValues = [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role->code,
                'professional_profile' => $profile?->code,
            ];

            if ($oldValues !== $newValues) {
                $this->auditor->record(
                    'user.update',
                    entity: $user,
                    newValues: $newValues,
                    oldValues: $oldValues,
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            if ($oldValues['role'] !== $newValues['role']) {
                $this->auditor->record(
                    'user.role.assign',
                    entity: $user,
                    newValues: ['role' => $newValues['role']],
                    oldValues: ['role' => $oldValues['role']],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            if ($oldValues['professional_profile'] !== $newValues['professional_profile']) {
                $this->auditor->record(
                    'user.profile.assign',
                    entity: $user,
                    newValues: ['professional_profile' => $newValues['professional_profile']],
                    oldValues: ['professional_profile' => $oldValues['professional_profile']],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            if ($hasOverrides) {
                $newOverrides = $this->auditOverrides($data['permission_overrides']);

                if ($oldOverrides !== $newOverrides) {
                    $this->auditor->record(
                        'user.permissions.assign',
                        entity: $user,
                        newValues: ['overrides' => $newOverrides],
                        oldValues: ['overrides' => $oldOverrides],
                        module: 'administration',
                        actor: $actorUser,
                    );
                }
            }

            if ($passwordChanged) {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();

                $this->auditor->record(
                    'user.password.reset_by_admin',
                    entity: $user,
                    newValues: ['password_changed' => true],
                    module: 'administration',
                    actor: $actorUser,
                );
            }

            return $user->load(['role', 'professionalProfile', 'permissions']);
        });
    }

    /** @param array<int, array{permission_id: int, name?: string, effect: string}> $overrides */
    private function pivotValues(array $overrides): array
    {
        return collect($overrides)->mapWithKeys(fn (array $override) => [
            $override['permission_id'] => ['effect' => $override['effect']],
        ])->all();
    }

    /** @param array<int, array{permission_id: int, name?: string, effect: string}> $overrides */
    private function auditOverrides(array $overrides): array
    {
        $names = Permission::query()
            ->whereIn('id', collect($overrides)->pluck('permission_id'))
            ->pluck('name', 'id');

        return collect($overrides)->map(fn (array $override) => [
            'permission' => $names->get($override['permission_id']),
            'effect' => $override['effect'],
        ])->sortBy('permission')->values()->all();
    }
}
