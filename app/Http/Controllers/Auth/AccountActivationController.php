<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ActivateAccountAction;
use App\Http\Controllers\Controller;
use App\Services\Audit\Auditor;
use App\Services\Auth\AccountActivation;
use App\Services\MailHosting\CpanelMailboxClient;
use App\Services\Webmail\WebmailSignOn;
use App\Support\SecurePassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ADR-202 — la connexion en deux temps : l'adresse d'abord, puis ce qu'elle appelle.
 *
 *  - un compte qui attend sa première connexion : « Bonjour Vola, vous êtes
 *    médecin », puis nouveau mot de passe et confirmation ;
 *  - tout le reste — compte déjà utilisé, adresse inconnue, compte désactivé : le
 *    mot de passe, comme d'habitude. L'écran ne dit jamais qu'une adresse n'existe pas.
 */
class AccountActivationController extends Controller
{
    public function identify(Request $request, AccountActivation $activation, CpanelMailboxClient $hosting): JsonResponse
    {
        $validated = $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.required' => 'Saisissez votre adresse email.', 'email.email' => 'Cette adresse email n’est pas valide.'],
        );

        $user = $activation->find($validated['email']);

        if (! $activation->opensFor($user)) {
            return response()->json(['mode' => 'password']);
        }

        $greeting = $activation->greeting($user);

        // La boîte recevra le même mot de passe : l'accès à l'hébergeur se prépare
        // pendant que la personne choisit le sien (ADR-190).
        if ($greeting['mailbox'] !== null && $hosting->configured()) {
            dispatch(function () use ($hosting): void {
                try {
                    $hosting->prepare();
                } catch (Throwable) {
                    // Seulement une avance : l'activation le refera.
                }
            })->afterResponse();
        }

        return response()->json([
            'mode' => 'activate',
            'greeting' => $greeting,
            'open_until' => $user->activation_open_until?->toIso8601String(),
        ]);
    }

    public function activate(Request $request, ActivateAccountAction $action, Auditor $auditor, WebmailSignOn $webmail): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', SecurePassword::rule()],
            'remember' => ['sometimes', 'boolean'],
        ], [
            'password.required' => 'Choisissez votre mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
        ]);

        $result = $action->execute($validated['email'], $validated['password'], [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $user = $result['user'];
        $remember = $request->boolean('remember');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        // Sa boîte a reçu le même mot de passe : la messagerie s'ouvrira sans le redemander (ADR-200).
        if ($result['mailbox_ready']) {
            $webmail->rememberAtLogin($user, $validated['password'], $remember);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $auditor->record('login', entity: $user, newValues: ['first_login' => true], module: 'auth', actor: $user);

        return redirect()->intended(route('dashboard'))->with('status', 'Bienvenue, '.$user->name.' : votre compte est activé.');
    }
}
