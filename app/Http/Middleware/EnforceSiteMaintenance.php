<?php

namespace App\Http\Middleware;

use App\Services\Settings\SiteMaintenanceState;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-193 — un site en maintenance répond par sa page de maintenance (503), avec
 * le message réglé depuis le portail.
 *
 * Restent ouverts :
 *   - la connexion, la déconnexion et le mot de passe oublié : le compte qui a le
 *     droit `app_maintenance.bypass` doit pouvoir se connecter pour vérifier le site ;
 *   - le logo, l'icône et robots.txt, que la page de maintenance elle-même utilise ;
 *   - l'API du site (hors du groupe `web`) : le portail garde la main pour lever
 *     la maintenance ;
 *   - le portail : il n'est jamais mis en maintenance (`SiteMaintenanceState::applies()`).
 *
 * La page est un écran Inertia rendu avec le statut 503 : une navigation en
 * cours la reçoit comme n'importe quelle page. Un appel qui attend du JSON reçoit
 * du JSON.
 */
class EnforceSiteMaintenance
{
    /** @var list<string> */
    private const OPEN_PATHS = [
        'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/*',
        'branding/*', 'robots.txt', 'up',
    ];

    public function __construct(private readonly SiteMaintenanceState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! SiteMaintenanceState::applies()
            || $request->is(...self::OPEN_PATHS)
            || ! $this->state->isActive()
            || $this->state->canBypass($request->user())) {
            return $next($request);
        }

        $retryAfter = $this->state->retryAfterSeconds();
        $headers = $retryAfter !== null ? ['Retry-After' => (string) $retryAfter] : [];

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $notice = $this->state->notice();

            return response()->json(['message' => $notice['title'], 'maintenance' => $notice], 503, $headers);
        }

        $response = Inertia::render('Maintenance', ['notice' => $this->state->notice()])->toResponse($request);
        $response->setStatusCode(503);
        $response->headers->add($headers);

        return $response;
    }
}
