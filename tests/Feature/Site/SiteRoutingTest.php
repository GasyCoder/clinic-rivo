<?php

namespace Tests\Feature\Site;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_site_shows_the_site_selection_page_with_all_three_clinics(): void
    {
        config(['rivo.site.type' => 'public']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SiteSelect')
            ->has('clinics', 3)
            ->where('clinics.0.code', 'M')
            ->where('clinics.0.name', 'Mampikony')
            ->where('clinics.0.url', 'https://clinique-m.rivo.mg')
            ->where('clinics.1.code', 'A')
            ->where('clinics.1.name', 'Ambondromamy')
            ->where('clinics.2.code', 'B')
            ->where('clinics.2.name', 'Boriziny')
        );
    }

    public function test_public_site_does_not_expose_the_login_route(): void
    {
        config(['rivo.site.type' => 'public']);

        $this->get('/login')->assertNotFound();
    }

    public function test_public_site_does_not_expose_the_logout_route_even_for_an_authenticated_session(): void
    {
        config(['rivo.site.type' => 'public']);

        // Acting as a user isolates this from the 'auth' middleware's own
        // guest redirect (which would otherwise mask the 404 behind a
        // redirect to /login) — a public deployment has no login route in
        // the first place, so no such session could exist for real, but
        // this confirms the site-type gate itself, independent of auth.
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertNotFound();
    }

    public function test_clinic_deployment_sends_guests_straight_to_login_without_asking_for_a_site(): void
    {
        config(['rivo.site.type' => 'clinic']);

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_admin_deployment_sends_guests_straight_to_login_without_asking_for_a_site(): void
    {
        config(['rivo.site.type' => 'admin']);

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_clinic_and_admin_deployments_share_the_same_login_flow(): void
    {
        foreach (['clinic', 'admin'] as $type) {
            config(['rivo.site.type' => $type]);

            $this->get('/login')
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('Auth/Login'));
        }
    }

    public function test_authenticated_users_reach_the_dashboard_on_both_clinic_and_admin_deployments(): void
    {
        $user = User::factory()->create();

        foreach (['clinic', 'admin'] as $type) {
            config(['rivo.site.type' => $type]);

            $this->actingAs($user)
                ->get('/')
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('Home'));
        }
    }

    public function test_public_deployment_never_shows_the_dashboard_even_when_a_session_is_authenticated(): void
    {
        config(['rivo.site.type' => 'public']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SiteSelect'));
    }
}
