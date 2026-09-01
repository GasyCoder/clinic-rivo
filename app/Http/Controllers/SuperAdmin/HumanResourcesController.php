<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HumanResourcesController extends Controller
{
    public function __invoke(Request $request, PortalSiteApiClient $client): Response
    {
        $sites = collect($client->humanResourcesForAllSites($request->user()));
        $online = $sites->where('ok', true);

        return Inertia::render('SuperAdmin/HumanResources/Index', [
            'sites' => $sites->values(),
            'summary' => [
                'online_sites' => $online->count(),
                'active_employees' => $online->sum(fn (array $site) => data_get($site, 'data.summary.active_employees', 0)),
                'current_contracts' => $online->sum(fn (array $site) => data_get($site, 'data.summary.current_contracts', 0)),
                'open_attendance' => $online->sum(fn (array $site) => data_get($site, 'data.summary.open_attendance', 0)),
                'pending_leave' => $online->sum(fn (array $site) => data_get($site, 'data.summary.pending_leave', 0)),
                'upcoming_shifts' => $online->sum(fn (array $site) => data_get($site, 'data.summary.upcoming_shifts', 0)),
            ],
        ]);
    }
}
