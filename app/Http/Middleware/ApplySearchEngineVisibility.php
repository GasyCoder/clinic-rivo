<?php

namespace App\Http\Middleware;

use App\Services\Settings\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-184 — une application masquée aux moteurs de recherche le dit sur chaque
 * réponse, pas seulement dans ses pages : un logo, un document ou une réponse
 * d'API n'ont pas de balise `<meta>` où porter la consigne. L'en-tête
 * `X-Robots-Tag` la porte partout, robots.txt compris.
 */
class ApplySearchEngineVisibility
{
    public function __construct(private readonly AppSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->settings->hiddenFromSearchEngines()) {
            $response->headers->set('X-Robots-Tag', AppSettings::ROBOTS_DIRECTIVES);
        }

        return $response;
    }
}
