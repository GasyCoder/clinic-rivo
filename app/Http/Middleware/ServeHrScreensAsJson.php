<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-187 — les écrans RH du site, lus par le portail.
 *
 * Les contrôleurs RH répondent comme à un navigateur : une page Inertia, une
 * redirection avec un message, un fichier. Le portail, lui, a besoin de
 * données. Cet intermédiaire traduit, sans rien changer aux contrôleurs :
 *
 * - page Inertia      → son JSON Inertia ({component, props, url}) ;
 * - redirection       → {redirect, status, error}, le chemin relatif au site ;
 * - erreurs en session → 422 avec les erreurs, comme une validation ;
 * - fichier, JSON     → inchangés.
 */
class ServeHrScreensAsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        // Inertia répond en JSON à une visite qui porte cet en-tête ; une
        // erreur de validation répond en JSON à qui l'accepte.
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $session = app('session.store');
        $errors = $session->pull('errors');
        $status = $session->pull('status');
        $error = $session->pull('error');

        if ($errors instanceof ViewErrorBag && $errors->any()) {
            return new JsonResponse([
                'message' => $errors->first(),
                'errors' => $errors->getBag('default')->toArray(),
            ], 422);
        }

        $target = $response->getTargetUrl();
        $path = (string) (parse_url($target, PHP_URL_PATH) ?: '/');
        $query = parse_url($target, PHP_URL_QUERY);

        return new JsonResponse([
            'redirect' => $path.($query ? '?'.$query : ''),
            'status' => is_string($status) ? $status : null,
            'error' => is_string($error) ? $error : null,
        ]);
    }
}
