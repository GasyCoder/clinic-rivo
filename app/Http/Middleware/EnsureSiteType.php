<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the deployment type(s) it makes sense for
 * (`site.type:clinic,admin`, `site.type:public`, ...).
 *
 * Reads `config('rivo.site.type')` at request time rather than gating at
 * route-registration time — deliberately, so tests can simulate any
 * deployment with `config(['rivo.site.type' => 'admin'])` without rebooting
 * the framework, and so a single `/` route can serve every deployment type.
 */
class EnsureSiteType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        abort_unless(in_array(config('rivo.site.type'), $types, true), 404);

        return $next($request);
    }
}
