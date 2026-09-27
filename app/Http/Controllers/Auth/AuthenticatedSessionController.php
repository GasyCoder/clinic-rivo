<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Audit\Auditor;
use App\Services\Webmail\WebmailSignOn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, Auditor $auditor, WebmailSignOn $webmail): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // ADR-200 — le même mot de passe ouvre sa boîte email : gardé chiffré dans
        // la session (et sur l'appareil avec « Se souvenir de moi »), il évite de le
        // retaper dans la messagerie.
        $webmail->rememberAtLogin($request->user(), $request->string('password')->toString(), $request->boolean('remember'));

        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();
        // ADR-202 — un compte déjà utilisé avant la première connexion guidée est « activé »
        // à sa première connexion : il n'est plus jamais proposé au choix d'un mot de passe.
        $request->user()->markActivated();

        $auditor->record('login', entity: $request->user(), module: 'auth');

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, Auditor $auditor): RedirectResponse
    {
        // Captured before logout: the session (and $request->user()) is
        // gone once Auth::logout() runs, so the actor must be passed
        // explicitly rather than resolved from the request at record time.
        $user = $request->user();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $auditor->record('logout', entity: $user, module: 'auth', actor: $user);

        return redirect('/');
    }
}
