<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\TrashCategory;
use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogActor;
use App\Services\Trash\TrashDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrashController extends Controller
{
    public function index(Request $request, TrashDirectory $trash): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot('trash.view')) {
            throw new AuthorizationException('La consultation de la corbeille n’est pas autorisée.');
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['ALL', ...array_column(TrashCategory::options(), 'code')])],
            'deleted_from' => ['nullable', 'date_format:Y-m-d'],
            'deleted_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:deleted_from'],
        ]);
        $validated['category'] ??= 'ALL';
        $result = $trash->list($validated);

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function restore(
        Request $request,
        string $category,
        string $uuid,
        TrashDirectory $trash,
    ): JsonResponse {
        $validated = validator(
            ['category' => $category, 'uuid' => $uuid],
            ['category' => [Rule::enum(TrashCategory::class)], 'uuid' => ['required', 'uuid']],
        )->validate();
        $result = $trash->restore(
            TrashCategory::from($validated['category']),
            $validated['uuid'],
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json([
            'message' => $result['already_restored']
                ? 'Cet élément était déjà restauré.'
                : 'Élément restauré et opération auditée.',
            'data' => $result,
        ]);
    }

    /** Destroy a trashed record for good — refused as soon as anything used it. */
    public function destroy(
        Request $request,
        string $category,
        string $uuid,
        TrashDirectory $trash,
    ): JsonResponse {
        $validated = validator(
            ['category' => $category, 'uuid' => $uuid],
            ['category' => [Rule::enum(TrashCategory::class)], 'uuid' => ['required', 'uuid']],
        )->validate();

        return response()->json([
            'message' => 'Élément supprimé définitivement.',
            'data' => $trash->forceDelete(
                TrashCategory::from($validated['category']),
                $validated['uuid'],
                CatalogActor::fromRemoteRequest($request),
            ),
        ]);
    }

    /** ADR-236 — vider la corbeille du site : ce qui n'a servi nulle part, selon les filtres. */
    public function empty(Request $request, TrashDirectory $trash): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['ALL', ...array_column(TrashCategory::options(), 'code')])],
            'deleted_from' => ['nullable', 'date_format:Y-m-d'],
            'deleted_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:deleted_from'],
        ]);
        $validated['category'] ??= 'ALL';
        $report = $trash->empty($validated, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => $report['deleted'] === 0
                ? 'Rien à supprimer : tout ce qui reste a servi.'
                : "{$report['deleted']} élément".($report['deleted'] > 1 ? 's' : '').' supprimé'.($report['deleted'] > 1 ? 's' : '').' définitivement.',
            'data' => $report,
        ]);
    }
}
