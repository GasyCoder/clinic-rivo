<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRivoSiteApi
{
    /** Roughly 1 500 permission names — far above the whole catalogue. */
    private const MAX_PERMISSIONS_HEADER_LENGTH = 32_768;

    public function handle(Request $request, Closure $next): Response
    {
        if (config('rivo.site.type') !== 'clinic') {
            return $this->error('Cette API est disponible uniquement sur un site clinique.', 404);
        }

        $expectedToken = trim((string) config('rivo.site_api.token'));

        if ($expectedToken === '') {
            return $this->error("L'API du site n'est pas configurée.", 503);
        }

        $providedToken = trim((string) $request->bearerToken());

        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return $this->error("Clé d'API invalide.", 401);
        }

        $requestUuid = trim((string) $request->header('X-Request-UUID'));

        if (! Str::isUuid($requestUuid)) {
            return $this->error('Un en-tête X-Request-UUID valide est obligatoire.', 422);
        }

        $rawPermissions = (string) $request->header('X-Rivo-Actor-Permissions');

        // Truncating an authorization list silently is worse than refusing it:
        // a Super Admin holding every permission of a growing catalogue would
        // keep losing the last ones and get baffling 403s on the site. The
        // header stays bounded, but going over it fails loudly.
        if (mb_strlen($rawPermissions) > self::MAX_PERMISSIONS_HEADER_LENGTH) {
            return $this->error('L’en-tête des permissions de l’acteur distant dépasse la taille autorisée.', 422);
        }

        $request->attributes->set('audit_request_uuid', $requestUuid);
        $request->attributes->set('rivo_actor_uuid', mb_substr(trim((string) $request->header('X-Rivo-Actor-UUID')), 0, 36));
        $request->attributes->set('rivo_actor_name', mb_substr(trim((string) $request->header('X-Rivo-Actor-Name')), 0, 150));
        $request->attributes->set(
            'rivo_actor_permissions',
            collect(explode(',', $rawPermissions))
                ->map(fn (string $permission) => trim($permission))
                ->filter(fn (string $permission) => preg_match('/^[a-z0-9_.-]{1,100}$/', $permission) === 1)
                ->unique()
                ->values()
                ->all(),
        );

        return $next($request);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
