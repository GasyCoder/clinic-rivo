<?php

namespace App\Http\Controllers;

use App\Services\SuperAdmin\PortalDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function site(Request $request, string $site, PortalDirectory $directory): Response|RedirectResponse
    {
        $siteData = $directory->site($site);
        $requestedModule = mb_strtoupper((string) $request->query('module', 'OVERVIEW'));
        $module = collect($siteData['modules'])->firstWhere('code', $requestedModule);

        abort_unless($module, 404);

        // ADR-182 — un ancien lien vers la vitrine RH mène à l'espace RH du site.
        if ($module['code'] === 'HR') {
            return redirect()->route('super-admin.sites.hr', ['site' => $siteData['code']]);
        }

        return Inertia::render('SuperAdmin/Sites/Show', [
            'clinic' => $siteData,
            'selectedModule' => $module,
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
