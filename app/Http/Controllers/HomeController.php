<?php

namespace App\Http\Controllers;

use App\Services\Administration\HrOverviewService;
use App\Services\Catalog\CatalogActor;
use App\Services\Dashboard\ClinicOverviewService;
use App\Services\Dashboard\SiteReportService;
use App\Services\Pharmacy\PharmacyWorkspaceService;
use App\Services\SuperAdmin\PortalDirectory;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resolves the root `/` for whichever deployment is currently running:
 *
 * - gateway (app.rivo.mg): the staff "choose your site" entry point (no
 *   auth involved) — not to be confused with cliniquesaintgeorges.mg, the
 *   public marketing site, which is untouched and out of scope here.
 * - clinic / admin: the dashboard, behind the same session login used by
 *   every operational deployment for now (a dedicated Super Admin auth
 *   flow is future work — this step only prepares site identity/routing).
 */
class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        PortalDirectory $directory,
        ClinicOverviewService $clinicOverview,
        PortalSiteApiClient $client,
    ): Response|RedirectResponse {
        $deploymentType = config('rivo.site.type');

        abort_unless(
            in_array($deploymentType, ['clinic', 'admin', 'gateway'], true),
            500,
            'RIVO_SITE_TYPE doit être clinic, admin ou gateway.',
        );

        if ($deploymentType === 'gateway') {
            return Inertia::render('SiteSelect', [
                'clinics' => collect(config('rivo.clinics'))->map(fn (array $clinic) => [
                    'code' => $clinic['code'],
                    'name' => $clinic['name'],
                    'url' => $clinic['url'],
                ]),
                'adminUrl' => config('rivo.admin_url'),
            ]);
        }

        if (! $request->user()) {
            return redirect()->route('login');
        }

        if (! $request->user()->isActive() || ! $request->user()->role_id || ! $request->user()->role()->exists()) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => "Ce compte n'est pas actif ou ne possède aucun rôle valide.",
            ]);
        }

        if ($deploymentType === 'admin') {
            abort_unless(
                $request->user()->hasRole('SUPER_ADMIN')
                && $request->user()->can('super_admin.portal.view'),
                403,
            );

            // ADR-102 — le tableau de bord central lit chaque site par son
            // API, jamais sa base. Une fenêtre trop large rend la courbe
            // illisible : le service borne lui-même la valeur reçue.
            // La valeur est bornée **avant** l'appel, pas seulement à
            // l'affichage : envoyée telle quelle, une fenêtre hors bornes
            // était refusée par chaque site, et les trois rapports
            // revenaient « injoignable » pour une faute de saisie.
            $days = max(
                SiteReportService::MIN_DAYS,
                min(SiteReportService::MAX_DAYS, (int) $request->integer('days', SiteReportService::DEFAULT_DAYS)),
            );

            return Inertia::render('SuperAdmin/Dashboard', [
                'sites' => $directory->sites(),
                'modules' => $directory->modules(),
                'reports' => $client->reportsForAllSites($request->user(), $days),
                'days' => $days,
            ]);
        }

        return Inertia::render('Home', [
            'overview' => $clinicOverview->for($request->user()),
            // ADR-098 — the Pharmacy's tasks live on the overview, not on a
            // second home page.
            // ADR-066 — the HR tasks of the day, for accounts working in HR.
            'hr' => $request->user()->can('employees.view')
                ? ['summary' => app(HrOverviewService::class)->overview(CatalogActor::fromUser($request->user()))['summary']]
                : null,
            'pharmacy' => $request->user()->can('pharmacy.view')
                ? app(PharmacyWorkspaceService::class)->dashboard($request->user())
                : null,
        ]);
    }
}
