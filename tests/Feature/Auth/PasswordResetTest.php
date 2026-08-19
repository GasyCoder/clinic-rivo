<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_a_reset_link_is_sent_for_a_known_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_same_status_is_shown_for_an_unknown_email_to_avoid_leaking_account_existence(): void
    {
        Notification::fake();

        $known = User::factory()->create();

        $knownResponse = $this->post('/forgot-password', ['email' => $known->email]);
        $unknownResponse = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame(
            $knownResponse->getSession()->get('status'),
            $unknownResponse->getSession()->get('status'),
        );
        Notification::assertSentTo($known, ResetPassword::class);
        Notification::assertNothingSentTo(User::factory()->make(['email' => 'nobody@example.com']));
    }

    public function test_reset_password_screen_can_be_rendered_from_the_emailed_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email));

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->component('Auth/ResetPassword')
                ->where('email', $user->email)
                ->where('token', $notification->token)
            );

            return true;
        });
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-strong-password',
                'password_confirmation' => 'new-strong-password',
            ]);

            $response->assertRedirect('/login');
            $this->assertTrue(Hash::check('new-strong-password', $user->fresh()->password));
            $this->assertGuest();

            return true;
        });
    }

    public function test_password_reset_is_audited(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-strong-password',
                'password_confirmation' => 'new-strong-password',
            ]);

            $log = AuditLog::where('action', 'password.reset')->first();
            $this->assertNotNull($log);
            $this->assertSame($user->id, $log->user_id);
            $this->assertSame(User::class, $log->entity_type);
            $this->assertSame($user->id, $log->entity_id);
            $this->assertSame('auth', $log->module);

            return true;
        });
    }

    public function test_password_is_not_reset_with_an_invalid_token(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;

        $response = $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-strong-password',
            'password_confirmation' => 'new-strong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_password_reset_requires_password_confirmation_to_match(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-strong-password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
