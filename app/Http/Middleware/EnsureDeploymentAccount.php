<?php

namespace App\Http\Middleware;

use App\Services\Authorization\DeploymentAccountPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revokes sessions that became incoherent with the current deployment.
 * LoginRequest applies the same policy at authentication time.
 */
class EnsureDeploymentAccount
{
    public function __construct(private readonly DeploymentAccountPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('rivo.site.type') === 'gateway') {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $this->policy->allows($user)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => $this->policy->denialMessage(),
        ]);
    }
}
