<?php

namespace App\Http\Controllers;

use App\Services\SuperAdmin\PortalDirectory;
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
    public function __invoke(Request $request, PortalDirectory $directory): Response|RedirectResponse
    {
        if (config('rivo.site.type') === 'gateway') {
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

        if (config('rivo.site.type') === 'admin') {
            abort_unless($request->user()->can('super_admin.portal.view'), 403);

            return Inertia::render('SuperAdmin/Dashboard', [
                'sites' => $directory->sites(),
                'modules' => $directory->modules(),
            ]);
        }

        return Inertia::render('Home');
    }
}
