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

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, $auditor) {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')),
                    'remember_token' => Str::random(60),
                ])->save();

                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();

                event(new PasswordReset($user));

                $auditor->record('password.reset', entity: $user, module: 'auth', actor: $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Ce lien de réinitialisation est invalide ou a expiré.',
            ]);
        }

        return redirect()->route('login')->with(
            'status',
            'Votre mot de passe a été modifié. Vous pouvez vous connecter.'
        );
    }
}
