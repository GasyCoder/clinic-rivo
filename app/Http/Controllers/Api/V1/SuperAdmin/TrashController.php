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
}
