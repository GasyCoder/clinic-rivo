<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-066 / ADR-187 — les RH de tous les sites, comparées, par l'API de chacun.
 * Les chiffres et leurs noms sont ceux de l'espace RH du site (HrOverviewService).
 *
 * L'accueil RH d'un site n'existe qu'une fois : c'est l'écran du site, relayé
 * (`/super-admin/sites/{code}/rh`). Cette page ne garde que le comparatif ;
 * une ancienne adresse `?site=A` mène à l'accueil du site.
 */
class HumanResourcesController extends Controller
{
    private const FIGURES = [
        'active_employees', 'inactive_employees', 'archived_employees', 'current_contracts',
        'contracts_ending_soon', 'today_attendance', 'open_attendance', 'pending_leave', 'upcoming_shifts', 'on_leave_today',
    ];

    public function __invoke(Request $request, PortalSiteApiClient $client): Response|RedirectResponse
    {
        $code = $request->query('site');

        if (is_string($code) && collect(config('rivo.clinics', []))->contains('code', $code)) {
            return redirect()->route('super-admin.sites.hr', ['site' => $code]);
        }

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
