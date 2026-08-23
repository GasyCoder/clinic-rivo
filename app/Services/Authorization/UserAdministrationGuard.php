<?php

namespace App\Services\Authorization;

use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UserAdministrationGuard
{
    public function assertCanManageTarget(User $actor, User $target): void
    {
        if (config('rivo.site.type') !== 'admin' && $target->hasRole('SUPER_ADMIN')) {
            throw ValidationException::withMessages([
                'user' => 'Un compte Super Administration ne peut pas être géré depuis un site opérationnel.',
            ]);
        }

        if ($target->hasRole('SUPER_ADMIN') && ! $actor->can('users.assign_super_admin')) {
            throw ValidationException::withMessages([
                'user' => 'Seul un Super Administrateur peut gérer un compte Super Administrateur.',
            ]);
        }
    }

    public function assertCanAssignRole(User $actor, Role $role): void
    {
        if (config('rivo.site.type') !== 'admin' && $role->code === 'SUPER_ADMIN') {
            throw ValidationException::withMessages([
                'role_id' => 'Le rôle Super Administrateur est réservé au portail central.',
            ]);
        }

        if ($role->code === 'SUPER_ADMIN' && ! $actor->can('users.assign_super_admin')) {
            throw ValidationException::withMessages([
                'role_id' => 'Vous ne pouvez pas attribuer le rôle Super Administrateur.',
            ]);
        }
    }

    public function assertProfileMatchesRole(Role $role, ?ProfessionalProfile $profile): void
    {
        $requiresProfile = $role->professionalProfiles()->active()->exists();

        if ($requiresProfile && ! $profile) {
            throw ValidationException::withMessages([
                'professional_profile_id' => 'Choisissez le profil métier de ce compte.',
            ]);
        }

        if ($profile && (! $profile->active || $profile->role_id !== $role->id)) {
            throw ValidationException::withMessages([
                'professional_profile_id' => 'Le profil métier choisi ne correspond pas au rôle sélectionné.',
            ]);
        }
    }

    public function assertCanChangeOwnRole(User $actor, User $target, Role $newRole): void
    {
        if ($actor->is($target) && $target->role_id !== $newRole->id) {
            throw ValidationException::withMessages([
                'role_id' => 'Vous ne pouvez pas modifier votre propre rôle.',
            ]);
        }
    }

    public function assertCanChangeOwnProfile(User $actor, User $target, ?ProfessionalProfile $newProfile): void
    {
        if ($actor->is($target) && $target->professional_profile_id !== $newProfile?->id) {
            throw ValidationException::withMessages([
                'professional_profile_id' => 'Vous ne pouvez pas modifier votre propre profil métier.',
            ]);
        }
    }

    public function assertCanChangeOwnPermissions(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'permission_overrides' => 'Vous ne pouvez pas modifier vos propres permissions individuelles.',
            ]);
        }
    }

    public function assertNotSelfDeactivation(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => 'Vous ne pouvez pas désactiver votre propre compte.',
            ]);
        }
    }

    /**
     * Must run inside the caller's transaction. lockForUpdate serializes
     * concurrent demotion/deactivation attempts on the Super Admin rows.
     */
    public function assertLastActiveSuperAdminPreserved(User $target, ?Role $replacementRole = null): void
    {
        $isRemovingSuperAdmin = $target->isActive()
            && $target->hasRole('SUPER_ADMIN')
            && ($replacementRole === null || $replacementRole->code !== 'SUPER_ADMIN');

        if (! $isRemovingSuperAdmin) {
            return;
        }

        $superRoleId = Role::query()->where('code', 'SUPER_ADMIN')->value('id');

        $activeSuperAdmins = User::query()
            ->where('role_id', $superRoleId)
            ->where('active', true)
            ->lockForUpdate()
            ->get(['id']);

        if ($activeSuperAdmins->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'Le dernier Super Administrateur actif ne peut pas être désactivé ou rétrogradé.',
            ]);
        }
    }
}
