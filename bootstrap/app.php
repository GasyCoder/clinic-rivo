<?php

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
        //
    })
    ->create();
