<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\SiteReportService;
use App\Services\SuperAdmin\PortalDirectory;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function site(
        Request $request,
        string $site,
        PortalDirectory $directory,
        PortalSiteApiClient $client,
    ): Response|RedirectResponse {
        $siteData = $directory->site($site);
        $requestedModule = mb_strtoupper((string) $request->query('module', 'OVERVIEW'));
        // Le registre complet permet de conserver les anciens favoris
        // `?module=`. Le menu du site, lui, ne porte que les espaces dont le
        // domicile canonique est l'établissement (ADR-231).
        $module = collect($directory->modules())->firstWhere('code', $requestedModule);

        abort_unless($module, 404);
        abort_unless($request->user()->can($module['permission']), 403);

        if ($module['navigation_scope'] === 'module') {
            return match ($module['code']) {
                'HR' => redirect()->route('super-admin.sites.hr', ['site' => $siteData['code']]),
                'PHARMACY' => redirect()->route('super-admin.sites.pharmacy', ['site' => $siteData['code']]),
                'LABORATORY' => redirect()->route('super-admin.sites.laboratory', ['site' => $siteData['code']]),
                'PARTNERS' => redirect()->route('super-admin.sites.partners', ['site' => $siteData['code']]),
                'CATALOG' => redirect()->route('super-admin.tariffs.index', ['site' => $siteData['code']]),
                'LOGISTICS' => redirect()->route('super-admin.workspaces.show', ['workspace' => 'logistics', 'site' => $siteData['code']]),
                'GUARDING' => redirect()->route('super-admin.workspaces.show', ['workspace' => 'guarding', 'site' => $siteData['code']]),
                default => abort(500, "Le module {$module['code']} n'a pas de route canonique dans le portail."),
            };
        }

        $siteData['modules'] = collect($siteData['modules'])
            ->filter(fn (array $siteModule): bool => $request->user()->can($siteModule['permission']))
            ->values()
            ->all();

        $days = max(
            SiteReportService::MIN_DAYS,
            min(SiteReportService::MAX_DAYS, (int) $request->integer('days', SiteReportService::DEFAULT_DAYS)),
        );

        return Inertia::render('SuperAdmin/Sites/Show', [
            'clinic' => $siteData,
            'selectedModule' => $module,
            'siteReport' => $client->reportForSite($siteData['code'], $request->user(), $days),
            'days' => $days,
        ]);
    }

    public function workspace(string $workspace, PortalDirectory $directory): Response|RedirectResponse
    {
        $code = mb_strtoupper($workspace);

        // ADR-184 — les paramètres ont leur écran : un ancien lien y mène.
        if ($code === 'SETTINGS') {
            return redirect()->route('super-admin.settings.index');
        }
        $permission = match ($code) {
            'FINANCE' => 'reports.financial.view',
            'HR' => 'employees.view',
            'LOGISTICS' => 'logistics.view',
            'GUARDING' => 'guarding.view',
            'TARIFFS' => 'catalog.items.view',
            'USERS' => 'users.view',
            'ROLES' => 'roles.view',
            'AUDIT' => 'audit.view',
            default => abort(404),
        };

        abort_unless(request()->user()->can($permission), 403);

        return Inertia::render('SuperAdmin/Workspace', [
            'workspace' => $directory->workspace($code),
            'sites' => $directory->sites(),
            'brand' => config('rivo.brand'),
        ]);
    }
}
