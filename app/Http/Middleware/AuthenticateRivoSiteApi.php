<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRivoSiteApi
{
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

        $request->attributes->set('audit_request_uuid', $requestUuid);
        $request->attributes->set('rivo_actor_uuid', mb_substr(trim((string) $request->header('X-Rivo-Actor-UUID')), 0, 36));
        $request->attributes->set('rivo_actor_name', mb_substr(trim((string) $request->header('X-Rivo-Actor-Name')), 0, 150));
        $request->attributes->set(
            'rivo_actor_permissions',
            collect(explode(',', (string) $request->header('X-Rivo-Actor-Permissions')))
                ->map(fn (string $permission) => trim($permission))
                ->filter(fn (string $permission) => preg_match('/^[a-z0-9_.-]{1,100}$/', $permission) === 1)
                ->unique()
                ->take(250)
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
