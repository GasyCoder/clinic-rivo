<?php

use App\Support\RequiredAbilities;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use App\Http\Middleware\AuthenticateRivoSiteApi;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureApiIdempotency;
use App\Http\Middleware\EnsureDeploymentAccount;
use App\Http\Middleware\EnsureSiteType;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // A care worksheet draft is a faithful snapshot of what the nurse
        // has on screen: an empty field must come back as an empty string,
        // not as null. Converting it would break the form it is restored
        // into (ADR-073).
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('care/orientations/*/draft'),
            fn (Request $request) => $request->is('medicine/orientations/*/draft'),
            fn (Request $request) => $request->is('maternity/orientations/*/draft'),
        ]);

        $middleware->alias([
            'account.active' => EnsureActiveAccount::class,
            'account.deployment' => EnsureDeploymentAccount::class,
            'site.type' => EnsureSiteType::class,
            'rivo.site-api' => AuthenticateRivoSiteApi::class,
            'api.idempotent' => EnsureApiIdempotency::class,
        ]);

        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ADR-154 — un refus doit dire ce qui manque.
        //
        // « Cette action n'est pas autorisée. » ne permettait pas de savoir
        // quel droit accorder, ni où : un droit coché au socle du rôle mais
        // refusé nominativement sur le compte (DENY > socle, ADR-033) se
        // lisait comme un défaut de l'application.
        //
        // `map()` et non `render()` : le handler convertit l'AuthorizationException
        // en HttpException **avant** de consulter les callbacks de rendu, qui
        // ne la voient donc jamais. `mapException()` s'exécute en premier.
        $exceptions->map(function (AuthorizationException $exception) {
            $request = request();
            $user = $request->user();
            $abilities = RequiredAbilities::forRoute($request->route());

            if (! $user || $abilities === []) {
                return $exception;
            }

            $missing = array_values(array_filter($abilities, fn (string $ability) => $user->cannot($ability)));

            if ($missing === []) {
                return $exception;
            }

            return new AccessDeniedHttpException(RequiredAbilities::explain($user, $missing), $exception);
        });
    })
    ->create();
