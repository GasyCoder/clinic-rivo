<?php

namespace App\Http\Middleware;

use App\Services\Webmail\WebmailAccess;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-194 — la messagerie s'ouvre à qui en a la permission et a une boîte à
 * ouvrir : la sienne (`webmail.view`) ou celle d'un autre employé
 * (`webmail.open_any`). Sinon, une page dit pourquoi et quoi faire : jamais un
 * simple refus.
 */
class EnsureWebmailAccess
{
    public function __construct(private readonly WebmailAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $this->access->hasSomethingToOpen($user)) {
            $why = $this->access->unavailableReason($user);

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Aucune boîte professionnelle à ouvrir pour ce compte.', 'reason' => $why['reason']], 403);
            }

            return Inertia::render('Webmail/Unavailable', [
                ...$why,
                'permission' => WebmailAccess::VIEW,
                'portal' => WebmailAccess::onPortal(),
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
