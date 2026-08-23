<?php

namespace App\Actions\User;

use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Authorization\UserAdministrationGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateUserAction
{
    public function __construct(
        private readonly UserAdministrationGuard $guard,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): User
    {
        if ($actor->cannot('users.create') || $actor->cannot('roles.assign')) {
            throw new AuthorizationException('Vous ne pouvez pas créer un utilisateur ou lui attribuer un rôle.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $role = Role::query()->lockForUpdate()->findOrFail($data['role_id']);
            $profile = filled($data['professional_profile_id'] ?? null)
                ? ProfessionalProfile::query()->lockForUpdate()->find($data['professional_profile_id'])
                : null;
            $this->guard->assertCanAssignRole($actor, $role);
            $this->guard->assertProfileMatchesRole($role, $profile);

            $overrides = $data['permission_overrides'] ?? [];

            if ($overrides !== [] && ! $actor->can('permissions.assign')) {
                throw ValidationException::withMessages([
                    'permission_overrides' => "Vous n'êtes pas autorisé à attribuer des permissions individuelles.",
                ]);
            }

            $user = new User([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'role_id' => $role->id,
                'professional_profile_id' => $profile?->id,
                'email_verified_at' => now(),
            ]);
            $user->forceFill(['active' => true])->save();

            if ($overrides !== []) {
                $user->permissions()->sync($this->pivotValues($overrides));
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
                ],
                module: 'administration',
                actor: $actor,
            );

            $this->auditor->record(
                'user.role.assign',
                entity: $user,
                newValues: ['role' => $role->code],
                module: 'administration',
                actor: $actor,
            );

            if ($profile) {
                $this->auditor->record(
                    'user.profile.assign',
                    entity: $user,
                    newValues: ['professional_profile' => $profile->code],
                    module: 'administration',
                    actor: $actor,
                );
            }

            if ($overrides !== []) {
                $this->auditor->record(
                    'user.permissions.assign',
                    entity: $user,
                    newValues: ['overrides' => $this->auditOverrides($overrides)],
                    module: 'administration',
                    actor: $actor,
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
