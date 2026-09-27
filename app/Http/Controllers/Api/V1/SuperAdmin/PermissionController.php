<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Permission\CreatePermissionAction;
use App\Actions\Permission\DeletePermissionAction;
use App\Actions\Permission\UpdatePermissionAction;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\Authorization\PermissionUsageScanner;
use App\Services\Authorization\RbacPresenter;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le catalogue des permissions d'un site (ADR-101).
 *
 * Comme le reste du domaine catalogue : le portail n'écrit jamais dans une
 * base clinique, il appelle cette API, qui revérifie l'autorisation et
 * conserve l'identité de l'acteur distant dans son audit (ADR-004, ADR-027).
 */
class PermissionController extends Controller
{
    public function __construct(
        private readonly RbacPresenter $presenter,
        private readonly PermissionUsageScanner $usage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'permissions.view');

        return response()->json([
            'data' => ['permissions' => $this->presenter->permissionCatalogWithUsage($this->usage)],
            'meta' => ['site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')]],
        ]);
    }

    public function store(Request $request, CreatePermissionAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'permissions.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $permission = $action->execute($validated, $actor);

        return response()->json([
            'message' => "Permission « {$permission->name} » créée.",
            'data' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'label' => $permission->label,
                // Créée à l'instant : aucun code ne la cite encore. L'écran
                // doit le dire plutôt que de la présenter comme un contrôle.
                'used_by_app' => false,
            ],
        ], 201);
    }

    public function update(Request $request, int $permissionId, UpdatePermissionAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'permissions.update');
        $permission = Permission::query()->findOrFail($permissionId);
        $validated = $request->validate(['label' => ['required', 'string', 'max:255']]);
        $permission = $action->execute($permission, $validated, $actor);

        return response()->json([
            'message' => "Permission « {$permission->name} » mise à jour.",
            'data' => ['id' => $permission->id, 'name' => $permission->name, 'label' => $permission->label],
        ]);
    }

    public function destroy(Request $request, int $permissionId, DeletePermissionAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'permissions.delete');
        $permission = Permission::query()->findOrFail($permissionId);
        $name = $permission->name;
        $action->execute($permission, $actor);

        return response()->json(['message' => "Permission « {$name} » retirée du catalogue."]);
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
