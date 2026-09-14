<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\SecurePassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Display the new-password form reached from the emailed link.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->query('email', ''),
            'token' => $request->route('token'),
            // Reached from a new account's welcome email: same form, but the
            // person is activating an account, not recovering a password.
            'welcome' => $request->boolean('welcome'),
        ]);
    }

    /**
     * Reset the password. Does not sign the user in afterwards — they
     * return to /login with the new password, not an active session
     * established from what may have been a shared/forwarded link.
     */
    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', SecurePassword::rule()],
        ]);

        // Each flow only accepts its own tokens: an invitation token lives in
        // its own table (config/auth.php), so a one-hour reset token cannot
        // be replayed here to gain an invitation's longer lifetime.
        $welcome = $request->boolean('welcome');
        $status = Password::broker($welcome ? 'invitations' : null)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, $auditor, $welcome) {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')),
                    'remember_token' => Str::random(60),
                ])->save();

                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();

                event(new PasswordReset($user));

                $auditor->record($welcome ? 'user.invite.accepted' : 'password.reset', entity: $user, module: 'auth', actor: $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => $welcome
                    ? 'Ce lien d’activation est invalide ou a expiré. Demandez à l’administration de vous renvoyer une invitation.'
                    : 'Ce lien de réinitialisation est invalide ou a expiré.',
            ]);
        }

        return redirect()->route('login')->with(
            'status',
            $welcome
                ? 'Votre compte est activé. Vous pouvez maintenant vous connecter avec votre mot de passe.'
                : 'Votre mot de passe a été modifié. Vous pouvez vous connecter.'
        );
    }
}
