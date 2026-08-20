<?php

namespace App\Http\Controllers;

use App\Services\SuperAdmin\PortalDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function site(Request $request, string $site, PortalDirectory $directory): Response
    {
        $siteData = $directory->site($site);
        $requestedModule = mb_strtoupper((string) $request->query('module', 'OVERVIEW'));
        $module = collect($siteData['modules'])->firstWhere('code', $requestedModule);

        abort_unless($module, 404);

        return Inertia::render('SuperAdmin/Sites/Show', [
            'clinic' => $siteData,
            'selectedModule' => $module,
        ]);
    }

    public function workspace(string $workspace, PortalDirectory $directory): Response
    {
        $code = mb_strtoupper($workspace);
        $permission = match ($code) {
            'FINANCE' => 'reports.financial.view',
            'HR' => 'employees.view',
            'LOGISTICS' => 'logistics.view',
            'GUARDING' => 'guarding.view',
            'USERS' => 'users.view',
            'ROLES' => 'roles.view',
            'SETTINGS' => 'settings.view',
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
