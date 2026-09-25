<?php

namespace App\Http\Middleware;

use App\Models\RemoteSuperAdmin;
use App\Models\Role;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-187 — pour un appel du portail, le Super Admin devient l'utilisateur de
 * la requête, avec les seuls droits que le portail a transmis.
 *
 * Posé après `rivo.site-api`, qui a vérifié le jeton du site et lu l'identité
 * et les droits de l'acteur. Les écrans du site qui passent derrière — `can:`,
 * règles, FormRequests, actions, audit — voient alors un acteur ordinaire,
 * sans qu'aucun d'eux n'ait à connaître le portail.
 */
class ActAsRemoteSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $uuid = trim((string) $request->attributes->get('rivo_actor_uuid'));
        $name = str((string) $request->attributes->get('rivo_actor_name'))->squish()->toString();
        $permissions = $request->attributes->get('rivo_actor_permissions', []);

        if (! Str::isUuid($uuid) || $name === '') {
            return new JsonResponse(['message' => 'L’identité du Super Administrateur est obligatoire.'], 422);
        }

        $actor = RemoteSuperAdmin::fromPortal(
            $uuid,
            mb_substr($name, 0, 150),
            is_array($permissions) ? $permissions : [],
            Role::query()->where('code', Role::PROTECTED_CODE)->value('id'),
        );

        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);

        return $next($request);
    }
}
