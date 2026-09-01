<?php

namespace App\Http\Controllers\Administration;

use App\Actions\User\ActivateUserAction;
use App\Actions\User\CreateUserAction;
use App\Actions\User\DeactivateUserAction;
use App\Actions\User\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DeactivateUserRequest;
use App\Http\Requests\Administration\StoreUserRequest;
use App\Http\Requests\Administration\UpdateUserRequest;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'inactive', 'all'], true)
            ? $request->query('status')
            : 'active';
        $roleCode = trim((string) $request->query('role', ''));
        $sourceProfileNames = ProfessionalProfile::query()->pluck('name', 'id');

        $users = User::query()
            ->with([
                'role:id,code,name',
                'professionalProfile:id,role_id,code,name',
                'permissions:id,name',
            ])
            ->whereHas('role', fn ($role) => $role->where('code', '!=', 'SUPER_ADMIN'))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($roleCode !== '', fn ($query) => $query->whereHas('role', fn ($role) => $role->where('code', $roleCode)))
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
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
                'last_login_at' => $user->last_login_at,
                'deactivated_at' => $user->deactivated_at,
                'deactivation_reason' => $user->deactivation_reason,
                'is_current' => $request->user()->is($user),
                'permission_overrides' => $user->permissions->map(fn (Permission $permission) => [
                    'permission_id' => $permission->id,
                    'name' => $permission->name,
                    'effect' => $permission->pivot->effect,
                    'source' => $permission->pivot->source,
                    'source_profile_id' => $permission->pivot->source_profile_id,
                    'source_profile_name' => $sourceProfileNames->get($permission->pivot->source_profile_id),
                ])->values(),
            ]);

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
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
                'profiles' => $role->professionalProfiles->map(fn ($profile) => [
                    'id' => $profile->id,
                    'code' => $profile->code,
                    'name' => $profile->name,
                    'description' => $profile->description,
                    'recommended_permissions' => $profile->recommendedPermissions
                        ->map(fn (Permission $permission) => [
                            'id' => $permission->id,
                            'name' => $permission->name,
                        ])
                        ->values(),
                ])->values(),
            ]);

        $permissions = $request->user()->can('permissions.view')
            ? Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'label' => $permission->label,
                'module' => str($permission->name)->before('.')->toString(),
            ])
            : collect();

        return Inertia::render('Administration/Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'permissionCatalog' => $permissions,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'role' => $roleCode,
            ],
        ]);
    }

    public function store(StoreUserRequest $request, CreateUserAction $action): RedirectResponse
    {
        $user = $action->execute($request->validated(), $request->user());

        return back()->with('status', "Compte de {$user->name} créé.");
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): RedirectResponse
    {
        $user = $action->execute($user, $request->validated(), $request->user());

        return back()->with('status', "Compte de {$user->name} mis à jour.");
    }

    public function deactivate(
        DeactivateUserRequest $request,
        User $user,
        DeactivateUserAction $action,
    ): RedirectResponse {
        $action->execute($user, $request->validated('reason'), $request->user());

        return back()->with('status', "Compte de {$user->name} désactivé.");
    }

    public function activate(Request $request, User $user, ActivateUserAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('users.activate'), 403);
        $action->execute($user, $request->user());

        return back()->with('status', "Compte de {$user->name} réactivé.");
    }
}
