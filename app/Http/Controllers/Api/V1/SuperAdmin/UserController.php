<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Role\UpdateRolePermissionsAction;
use App\Actions\User\ActivateUserAction;
use App\Actions\User\CreateUserAction;
use App\Actions\User\DeactivateUserAction;
use App\Actions\User\ForceDeleteUserAction;
use App\Actions\User\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Support\SecurePassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'users.view');

        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['active', 'inactive', 'all'], true)
            ? $request->query('status')
            : 'active';
        $roleCode = trim((string) $request->query('role', ''));
        $sourceProfileNames = ProfessionalProfile::query()->pluck('name', 'id');

        $userModels = User::query()
            ->with(['role:id,code,name', 'professionalProfile:id,role_id,code,name', 'permissions:id,name'])
            ->whereHas('role', fn ($role) => $role->where('code', '!=', 'SUPER_ADMIN'))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"),
            ))
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($roleCode !== '', fn ($query) => $query->whereHas('role', fn ($role) => $role->where('code', $roleCode)))
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        // One batched query instead of one AuditLog check per row — a user
        // with any audit trail anywhere can never be force-deleted (ADR-062).
        $auditedUserIds = AuditLog::query()
            ->whereIn('user_id', $userModels->pluck('id'))
            ->distinct()
            ->pluck('user_id');

        $users = $userModels->map(fn (User $user) => $this->serializeUser($user, $auditedUserIds, $sourceProfileNames));

        $roles = Role::query()
            ->with([
                'permissions:id,name',
                'professionalProfiles' => fn ($query) => $query
                    ->active()
                    ->with('recommendedPermissions:id,name')
                    ->orderBy('name'),
            ])
            ->where('code', '!=', 'SUPER_ADMIN')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->serializeRole($role));

        $permissions = Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'label' => $permission->label,
            'module' => str($permission->name)->before('.')->toString(),
        ]);

        return response()->json([
            'data' => [
                'users' => $users,
                'roles' => $roles,
                'permission_catalog' => $permissions,
            ],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
            ],
        ]);
    }

    public function store(Request $request, CreateUserAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.create');
        $this->authorizePermission($actor, 'roles.assign');
        $validated = $this->validated($request);
        $user = $action->execute($validated, $actor);

        return response()->json([
            'message' => "Compte de {$user->name} créé.",
            'data' => $this->serializeUser($user),
        ], 201);
    }

    public function update(Request $request, string $userUuid, UpdateUserAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.update');
        $this->authorizePermission($actor, 'roles.assign');
        $user = User::query()->where('uuid', $userUuid)->firstOrFail();
        $validated = $this->validated($request, ignoreUserId: $user->id);
        $user = $action->execute($user, $validated, $actor);

        return response()->json([
            'message' => "Compte de {$user->name} mis à jour.",
            'data' => $this->serializeUser($user),
        ]);
    }

    public function activate(Request $request, string $userUuid, ActivateUserAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.activate');
        $user = User::query()->where('uuid', $userUuid)->firstOrFail();
        $user = $action->execute($user, $actor);

        return response()->json([
            'message' => "Compte de {$user->name} réactivé.",
            'data' => $this->serializeUser($user),
        ]);
    }

    public function deactivate(Request $request, string $userUuid, DeactivateUserAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.deactivate');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $user = User::query()->where('uuid', $userUuid)->firstOrFail();
        $user = $action->execute($user, $validated['reason'], $actor);

        return response()->json([
            'message' => "Compte de {$user->name} désactivé.",
            'data' => $this->serializeUser($user),
        ]);
    }

    public function bulkDeactivate(Request $request, DeactivateUserAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.deactivate');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $count = DB::transaction(function () use ($validated, $action, $actor): int {
            $users = User::query()->whereIn('uuid', $validated['uuids'])->lockForUpdate()->get();

            if ($users->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Un compte sélectionné est introuvable. Aucune modification n’a été appliquée.',
                ]);
            }

            foreach ($users as $user) {
                $action->execute($user, $validated['reason'], $actor);
            }

            return $users->count();
        });

        return response()->json([
            'message' => "{$count} compte(s) désactivé(s).",
            'data' => ['processed' => $count],
        ]);
    }

    public function forceDelete(Request $request, string $userUuid, ForceDeleteUserAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.force_delete');
        $user = User::query()->where('uuid', $userUuid)->firstOrFail();
        $name = $user->name;
        $action->execute($user, $actor);

        return response()->json(['message' => "Compte de {$name} supprimé définitivement."]);
    }

    public function bulkForceDelete(Request $request, ForceDeleteUserAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.force_delete');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $count = DB::transaction(function () use ($validated, $action, $actor): int {
            $users = User::query()->whereIn('uuid', $validated['uuids'])->lockForUpdate()->get();

            if ($users->count() !== count($validated['uuids'])) {
                throw ValidationException::withMessages([
                    'uuids' => 'Un compte sélectionné est introuvable. Aucune suppression n’a été appliquée.',
                ]);
            }

            foreach ($users as $user) {
                $action->execute($user, $actor);
            }

            return $users->count();
        });

        return response()->json([
            'message' => "{$count} compte(s) supprimé(s) définitivement.",
            'data' => ['processed' => $count],
        ]);
    }

    public function updateRolePermissions(Request $request, string $roleCode, UpdateRolePermissionsAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.manage');
        $role = Role::query()->where('code', strtoupper($roleCode))->firstOrFail();

        $validated = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $role = $action->execute($role, $validated['permission_ids'], $actor);

        return response()->json([
            'message' => "Permissions du rôle {$role->name} mises à jour.",
            'data' => $this->serializeRole($role),
        ]);
    }

    /**
     * password is always optional: omitted on creation, the account is
     * provisioned by email invitation instead — a reset-password link,
     * sent by CreateUserAction, the same page as "forgot password".
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreUserId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($ignoreUserId),
            ],
            'password' => ['nullable', 'confirmed', SecurePassword::rule()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'professional_profile_id' => [
                Rule::requiredIf(fn () => ProfessionalProfile::query()
                    ->active()
                    ->where('role_id', (int) $request->input('role_id'))
                    ->exists()),
                'nullable',
                'integer',
                Rule::exists('professional_profiles', 'id')->where(
                    fn ($query) => $query
                        ->where('role_id', (int) $request->input('role_id'))
                        ->where('active', true),
                ),
            ],
            'sync_profile_permissions' => ['sometimes', 'boolean'],
            'permission_overrides' => ['sometimes', 'array'],
            'permission_overrides.*.permission_id' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')],
            'permission_overrides.*.effect' => ['required', Rule::in(['allow', 'deny'])],
        ]);
    }

    /** @return array<string, mixed> */
    /** @param Collection<int, int>|null $auditedUserIds */
    private function serializeUser(
        User $user,
        ?Collection $auditedUserIds = null,
        ?Collection $sourceProfileNames = null,
    ): array {
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
    private function serializeRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
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

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }

    private function authorizePermission(CatalogActor $actor, string $permission): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
