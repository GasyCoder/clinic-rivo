<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Role\ArchiveRoleAction;
use App\Actions\Role\CreateRoleAction;
use App\Actions\Role\RestoreRoleAction;
use App\Actions\Role\UpdateRoleAction;
use App\Actions\Role\UpdateRolePermissionsAction;
use App\Actions\User\UpdateUserPermissionOverridesAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\PermissionUsageScanner;
use App\Services\Authorization\RbacPresenter;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le référentiel des rôles d'un site, piloté depuis le portail (ADR-100).
 *
 * L'ADR-064 avait sorti le *socle* d'un rôle du code ; le rôle lui-même
 * restait figé dans `RoleSeeder`. Cet endpoint le rend administrable, avec
 * les mêmes garanties que le reste du domaine catalogue : autorisation
 * revérifiée côté site, acteur distant conservé dans l'audit (ADR-042/098),
 * jamais d'accès SQL direct depuis `admin.rivo.mg` (ADR-004/027).
 *
 * Séparé de `UserController` à la demande du propriétaire : gérer des comptes
 * et gérer le référentiel des rôles sont deux métiers, et deux écrans.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly RbacPresenter $presenter,
        private readonly PermissionUsageScanner $usage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'roles.view');

        $roles = Role::query()
            ->withTrashed()
            ->withCount('users')
            ->with([
                'permissions:id,name',
                'professionalProfiles' => fn ($query) => $query
                    ->active()
                    ->with('recommendedPermissions:id,name')
                    ->orderBy('name'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->presenter->role($role, $role->users_count));

        // Les exceptions individuelles vivent sur cet écran (décision du
        // propriétaire) : on sert donc les comptes du site, mais sans le
        // rôle SUPER_ADMIN, qui n'est jamais administrable depuis un site.
        $userModels = User::query()
            ->with(['role:id,code,name', 'professionalProfile:id,role_id,code,name', 'permissions:id,name'])
            ->whereHas('role', fn ($role) => $role->where('code', '!=', Role::PROTECTED_CODE))
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $auditedUserIds = AuditLog::query()
            ->whereIn('user_id', $userModels->pluck('id'))
            ->distinct()
            ->pluck('user_id');

        return response()->json([
            'data' => [
                'roles' => $roles,
                'users' => $userModels->map(fn (User $user) => $this->presenter->user($user, $auditedUserIds)),
                'permission_catalog' => $this->presenter->permissionCatalogWithUsage($this->usage),
            ],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
            ],
        ]);
    }

    public function store(Request $request, CreateRoleAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'roles.create');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:100'],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $role = $action->execute($validated, $actor);

        return response()->json([
            'message' => "Rôle {$role->name} créé.",
            'data' => $this->presenter->role($role, 0),
        ], 201);
    }

    public function update(Request $request, string $roleCode, UpdateRoleAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'roles.update');
        $role = $this->role($roleCode);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $role = $action->execute($role, $validated, $actor);

        return response()->json([
            'message' => "Rôle {$role->name} mis à jour.",
            'data' => $this->presenter->role($role->load('permissions', 'professionalProfiles')),
        ]);
    }

    public function archive(Request $request, string $roleCode, ArchiveRoleAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'roles.archive');
        $role = $this->role($roleCode);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $role = $action->execute($role, $validated['reason'], $actor);

        return response()->json([
            'message' => "Rôle {$role->name} archivé.",
            'data' => $this->presenter->role($role->load('permissions', 'professionalProfiles'), 0),
        ]);
    }

    public function restore(Request $request, string $roleCode, RestoreRoleAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'roles.restore');
        $role = $this->role($roleCode, withTrashed: true);
        $role = $action->execute($role, $actor);

        return response()->json([
            'message' => "Rôle {$role->name} restauré.",
            'data' => $this->presenter->role($role->load('permissions', 'professionalProfiles')),
        ]);
    }

    public function updatePermissions(Request $request, string $roleCode, UpdateRolePermissionsAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'users.manage');
        $role = $this->role($roleCode);

        $validated = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $role = $action->execute($role, $validated['permission_ids'], $actor);

        return response()->json([
            'message' => "Permissions du rôle {$role->name} mises à jour.",
            'data' => $this->presenter->role($role->load('professionalProfiles')),
        ]);
    }

    /**
     * Les exceptions individuelles d'un compte. Endpoint distinct de la
     * mise à jour du compte : cet écran ne modifie ni l'identité, ni le
     * rôle, et ne doit donc pas avoir à les réexpédier.
     */
    public function updateUserPermissions(Request $request, string $userUuid, UpdateUserPermissionOverridesAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'permissions.assign');
        $user = User::query()->where('uuid', $userUuid)->firstOrFail();

        $validated = $request->validate([
            'permission_overrides' => ['present', 'array'],
            'permission_overrides.*.permission_id' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')],
            'permission_overrides.*.effect' => ['required', Rule::in(['allow', 'deny'])],
        ]);

        $user = $action->execute($user, $validated['permission_overrides'], $actor);

        return response()->json([
            'message' => "Permissions individuelles de {$user->name} mises à jour.",
            'data' => $this->presenter->user($user),
        ]);
    }

    private function role(string $roleCode, bool $withTrashed = false): Role
    {
        return Role::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where('code', mb_strtoupper($roleCode))
            ->firstOrFail();
    }

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }
}
