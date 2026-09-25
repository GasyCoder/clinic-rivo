<?php

namespace App\Services\Authorization;

use App\Enums\AccountKind;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\EmployeeAccountLinker;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La projection unique des comptes, rôles et permissions servie au portail.
 *
 * Deux endpoints les décrivent désormais — « Utilisateurs » et
 * « Rôles & permissions », séparés à la demande du propriétaire. Recopiée,
 * la sérialisation aurait fini par diverger : le même compte se serait lu
 * différemment selon l'écran qui le regarde.
 */
class RbacPresenter
{
    /**
     * @param  Collection<int, int>|null  $auditedUserIds  Comptes ayant une trace d'audit,
     *                                                     calculés en un seul passage pour une liste.
     * @param  Collection<int, string>|null  $sourceProfileNames
     * @return array<string, mixed>
     */
    public function user(User $user, ?Collection $auditedUserIds = null, ?Collection $sourceProfileNames = null): array
    {
        $sourceProfileNames ??= ProfessionalProfile::query()
            ->whereIn('id', $user->permissions->pluck('pivot.source_profile_id')->filter())
            ->pluck('name', 'id');

        return [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'code' => $user->role->code,
                'name' => $user->role->name,
            ] : null,
            'professional_profile' => $user->professionalProfile ? [
                'id' => $user->professionalProfile->id,
                'code' => $user->professionalProfile->code,
                'name' => $user->professionalProfile->name,
            ] : null,
            'active' => $user->isActive(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $user->deactivation_reason,
            // ADR-183 — personnel clinique (relié à sa fiche Employé) ou externe.
            // Lu seulement si la fiche a été chargée : une liste la charge en
            // une requête, jamais une par compte.
            ...$this->accountKind($user),
            // ADR-062: a UI hint only — ForceDeleteUserAction re-verifies
            // this authoritatively regardless of what the client sends back.
            'deletable' => $user->last_login_at === null
                && ! ($auditedUserIds?->contains($user->id) ?? AuditLog::query()->where('user_id', $user->id)->exists()),
            'permission_overrides' => $user->permissions->map(fn (Permission $permission) => [
                'permission_id' => $permission->id,
                'name' => $permission->name,
                'effect' => $permission->pivot->effect,
                'source' => $permission->pivot->source,
                'source_profile_id' => $permission->pivot->source_profile_id,
                'source_profile_name' => $sourceProfileNames->get($permission->pivot->source_profile_id),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function accountKind(User $user): array
    {
        if (! $user->relationLoaded('employee')) {
            return [];
        }

        $employee = EmployeeAccountLinker::summary($user->employee);

        return [
            'account_kind' => ($employee ? AccountKind::Staff : AccountKind::External)->value,
            'employee' => $employee,
        ];
    }

    /** @return array<string, mixed> */
    public function role(Role $role, ?int $holders = null, ?int $holdersWithExceptions = null): array
    {
        $defaultPermissions = RolePermissionSeeder::defaultPermissionNames($role->code);

        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            // Le socle du rôle Super Admin n'est jamais édité ici : il tient
            // toutes les permissions sur le portail et aucune sur un site
            // (ADR-025, ADR-027). L'écran doit pouvoir le dire au lieu de
            // laisser cliquer un enregistrement qui sera refusé.
            'protected' => $role->isProtected(),
            'archived' => $role->trashed(),
            'archived_at' => $role->deleted_at?->toIso8601String(),
            'archive_reason' => $role->delete_reason,
            // Un rôle encore porté ne s'archive pas : le compte perdrait son
            // socle entier. Le compte est donné ici pour le dire avant le clic.
            'users_count' => $holders ?? $role->users()->count(),
            // ADR-150 — ce socle n'est pas la seule source des droits de ses
            // comptes : une exception individuelle l'emporte (ADR-033). Sans
            // ce compteur, un socle à 0 se lit « personne n'y a accès », et
            // un accès bien réel passe pour un défaut.
            'users_with_exceptions_count' => $holdersWithExceptions
                ?? $role->users()->whereHas('permissions')->count(),
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
            // A portal-created role has no honest factory default. `null`
            // lets the UI withhold reset instead of pretending that an empty
            // baseline is its default. Built-in roles may intentionally have
            // an empty default (SUPPORT/MAINTENANCE).
            'has_default_baseline' => $defaultPermissions !== null,
            'default_permissions' => $defaultPermissions?->values(),
            'profiles' => $role->professionalProfiles->map(fn (ProfessionalProfile $profile) => [
                'id' => $profile->id,
                'code' => $profile->code,
                'name' => $profile->name,
                'description' => $profile->description,
                'recommended_permissions' => $profile->recommendedPermissions
                    ->map(fn (Permission $permission) => ['id' => $permission->id, 'name' => $permission->name])
                    ->values(),
            ])->values(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function permissionCatalog(): Collection
    {
        return Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'label' => $this->label($permission),
            'module' => str($permission->name)->before('.')->toString(),
        ]);
    }

    /**
     * Jamais un libellé vide sur le fil.
     *
     * Une permission peut entrer en base sans libellé — un seeder partiel,
     * une migration qui n'a posé que le nom. Envoyé tel quel, ce vide a fait
     * tomber l'écran entier côté navigateur : trier sur `null` interrompt le
     * rendu, et Vue ne s'en relève pas. Le nom est le repli honnête : c'est
     * ce que le code écrit, et cela reste lisible.
     */
    private function label(Permission $permission): string
    {
        return filled($permission->label) ? $permission->label : $permission->name;
    }

    /**
     * Le même catalogue, enrichi de ce qu'on ne peut pas deviner en le
     * lisant : combien de rôles et de comptes le désignent, et surtout si
     * l'application le vérifie réellement quelque part (ADR-101).
     *
     * Ces trois informations décident de ce que l'écran autorise — une
     * permission encore citée ne se supprime pas — et de ce qu'il annonce :
     * une permission que rien ne vérifie n'ouvre rien, et le taire la
     * ferait passer pour une protection.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function permissionCatalogWithUsage(PermissionUsageScanner $usage): Collection
    {
        $used = $usage->usedNames();

        $roleCounts = DB::table('role_permissions')
            ->selectRaw('permission_id, count(*) as total')
            ->groupBy('permission_id')
            ->pluck('total', 'permission_id');

        $accountCounts = DB::table('user_permissions')
            ->selectRaw('permission_id, count(*) as total')
            ->groupBy('permission_id')
            ->pluck('total', 'permission_id');

        return Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'label' => $this->label($permission),
            'module' => str($permission->name)->before('.')->toString(),
            'used_by_app' => isset($used[$permission->name]),
            'roles_count' => (int) $roleCounts->get($permission->id, 0),
            'accounts_count' => (int) $accountCounts->get($permission->id, 0),
        ]);
    }
}
