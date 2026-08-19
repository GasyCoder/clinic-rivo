<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the forgot-password view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Send a reset link if the address is known.
     *
     * Deliberately shows the same status regardless of whether the email
     * exists, was throttled, or the link was actually sent — an
     * account-enumeration guard, not a UX shortcut.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return back()->with(
            'status',
            "Si cette adresse est associée à un compte, un lien de réinitialisation vient d'être envoyé."
        );
    }
}
