<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-066 — HR of every site, read-only, through each site's API. The figures
 * and their names are those of the clinic HR space (HrOverviewService).
 */
class HumanResourcesController extends Controller
{
    private const FIGURES = [
        'active_employees', 'inactive_employees', 'archived_employees', 'current_contracts',
        'contracts_ending_soon', 'today_attendance', 'open_attendance', 'pending_leave', 'upcoming_shifts',
    ];

    public function __invoke(Request $request, PortalSiteApiClient $client): Response
    {
        $sites = collect($client->humanResourcesForAllSites($request->user()));
        $online = $sites->where('ok', true);

        $total = collect(self::FIGURES)->mapWithKeys(fn (string $key) => [
            $key => $online->every(fn (array $site) => data_get($site, "data.summary.{$key}") === null)
                ? null
                : $online->sum(fn (array $site) => (int) data_get($site, "data.summary.{$key}", 0)),
        ])->all();

        return Inertia::render('SuperAdmin/HumanResources/Index', [
            'sites' => $sites->values(),
            'summary' => ['online_sites' => $online->count(), ...$total],
        ]);
    }
}
