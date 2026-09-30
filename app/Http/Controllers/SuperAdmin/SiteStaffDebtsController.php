<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Services\SuperAdmin\SiteStaffDebtGateway;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * ADR-229 — Finance › Dettes du personnel. « Tous les sites » d'abord : ce que chaque
 * site a en jeu, lu par son API (jamais sa base, ADR-004). Puis, pour un site, ses
 * écrans relayés : la liste, une dette, les réglages. Le DG y décide, verse, remet,
 * relance, règle les limites et les intérêts, exporte.
 */
class SiteStaffDebtsController extends SiteScreenController
{
    public function __invoke(Request $request, string $site, SiteStaffDebtGateway $gateway, string $path = ''): SymfonyResponse|InertiaResponse
    {
        return $this->relay($request, $site, $gateway, $path);
    }

    /** Jamais une redirection vers le premier site : un site injoignable y ramènerait sans fin. */
    public function overview(Request $request, SiteStaffDebtGateway $gateway, PortalSiteApiClient $sites): InertiaResponse
    {
        $results = collect($sites->staffDebtsOverviewForAllSites($request->user()))->keyBy(fn (array $result) => $result['site']['code'] ?? '');

        return Inertia::render('SuperAdmin/Finance/StaffDebts', [
            'sites' => collect(config('rivo.clinics', []))->map(function (array $site) use ($gateway, $results): array {
                $result = $results->get($site['code']) ?? [];

                return [
                    'code' => $site['code'],
                    'name' => $site['name'],
                    'url' => $gateway->base($site),
                    'configured' => $gateway->configured($site),
                    'status' => $result['status'] ?? null,
                    'ok' => (bool) ($result['ok'] ?? false),
                    'message' => $result['message'] ?? null,
                    'overview' => ($result['ok'] ?? false) ? ($result['data'] ?? null) : null,
                ];
            })->values(),
            'can' => [
                'settings' => $request->user()->can('staff_debts.settings'),
                'export' => $request->user()->can('staff_debts.export'),
            ],
        ]);
    }

    protected function contextKey(): string
    {
        return 'staffDebtContext';
    }

    protected function overviewRoute(): string
    {
        return 'super-admin.finance.staff-debts.index';
    }
}
