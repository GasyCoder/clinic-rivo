<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Administration\HrOverviewService;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HumanResourcesController extends Controller
{
    public function __invoke(Request $request, HrOverviewService $service): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot('employees.view')) {
            throw new AuthorizationException('Cette supervision RH distante n’est pas autorisée.');
        }

        return response()->json([
            'data' => $service->overview($actor),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'generated_at' => now()->toIso8601String(),
                'scope' => 'READ_ONLY',
            ],
        ]);
    }
}
