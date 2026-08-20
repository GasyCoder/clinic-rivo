<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_authenticated_users_are_redirected_away_from_the_login_screen(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_session_is_regenerated_on_login(): void
    {
        $user = User::factory()->withRole()->create();

        $this->get('/login');
        $originalSessionId = session()->getId();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertNotEquals($originalSessionId, session()->getId());
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_login_requests_are_rate_limited_after_too_many_attempts(): void
    {
        $user = User::factory()->withRole()->create();

        RateLimiter::clear(strtolower($user->email).'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->withRole()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_guests_can_not_logout(): void
    {
        $response = $this->post('/logout');

        $response->assertRedirect('/login');
    }

    public function test_deactivated_users_cannot_authenticate(): void
    {
        $user = User::factory()->withRole()->create([
            'active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Fin de contrat',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_roleless_users_cannot_authenticate(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_a_deactivated_account_is_logged_out_on_its_next_authenticated_request(): void
    {
        $user = User::factory()->withRole()->create();
        $user->forceFill(['active' => false, 'deactivated_at' => now()])->save();

        $response = $this->actingAs($user->fresh())->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }
}
