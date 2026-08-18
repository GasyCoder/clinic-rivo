<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resolves the root `/` for whichever deployment is currently running:
 *
 * - public: the "choose your site" entry point (no auth involved).
 * - clinic / admin: the dashboard, behind the same session login used by
 *   every operational deployment for now (a dedicated Super Admin auth
 *   flow is future work — this step only prepares site identity/routing).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (config('rivo.site.type') === 'public') {
            return Inertia::render('SiteSelect', [
                'clinics' => config('rivo.clinics'),
            ]);
        }

        if (! $request->user()) {
            return redirect()->route('login');
        }

        return Inertia::render('Home');
    }
}
