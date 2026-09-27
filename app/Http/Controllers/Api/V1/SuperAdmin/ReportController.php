<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogActor;
use App\Services\Dashboard\SiteReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le rapport consolidé d'un site, pour le tableau de bord central (ADR-102).
 *
 * `super_admin.portal.view` ouvre la porte ; chaque section est ensuite
 * gardée par la permission qui possède réellement la donnée, et une section
 * refusée revient « indisponible » avec son motif — jamais à zéro.
 */
class ReportController extends Controller
{
    public function overview(Request $request, SiteReportService $reports): JsonResponse
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot('super_admin.portal.view')) {
            throw new AuthorizationException('Cette lecture distante n’est pas autorisée.');
        }

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'between:'.SiteReportService::MIN_DAYS.','.SiteReportService::MAX_DAYS],
        ]);

        return response()->json([
            'data' => $reports->overview($actor, (int) ($validated['days'] ?? SiteReportService::DEFAULT_DAYS)),
            'meta' => ['site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')]],
        ]);
    }
}
