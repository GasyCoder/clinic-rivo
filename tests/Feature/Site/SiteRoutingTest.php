<?php

namespace Tests\Feature\Site;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function seedRbac(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    public function test_gateway_shows_the_site_selection_page_with_all_three_clinics(): void
    {
        config(['rivo.site.type' => 'gateway']);

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
            ->where('adminUrl', 'https://admin.rivo.mg')
        );
    }

    public function test_gateway_does_not_expose_the_login_route(): void
    {
        config(['rivo.site.type' => 'gateway']);

        $this->get('/login')->assertNotFound();
    }

    public function test_gateway_does_not_expose_the_logout_route_even_for_an_authenticated_session(): void
    {
        config(['rivo.site.type' => 'gateway']);

        // Acting as a user isolates this from the 'auth' middleware's own
        // guest redirect (which would otherwise mask the 404 behind a
        // redirect to /login) — the gateway has no login route in the
        // first place, so no such session could exist for real, but this
        // confirms the site-type gate itself, independent of auth.
        $user = User::factory()->withRole()->create();

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

    public function test_clinic_user_and_super_admin_reach_their_distinct_dashboards(): void
    {
        $this->seedRbac();
        $clinicUser = $this->user('RECEPTION');
        $superAdmin = $this->user('SUPER_ADMIN');

        config(['rivo.site.type' => 'clinic']);
        $this->actingAs($clinicUser)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home'));

        config(['rivo.site.type' => 'admin']);
        $this->actingAs($superAdmin)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Dashboard')
                ->has('sites', 3));
    }

    public function test_non_super_admin_permission_holder_cannot_enter_the_admin_portal_by_default(): void
    {
        $this->seedRbac();
        config(['rivo.site.type' => 'admin']);

        $this->actingAs($this->user('ADMINISTRATION'))->get('/')->assertForbidden();
    }

    public function test_gateway_never_shows_the_dashboard_even_when_a_session_is_authenticated(): void
    {
        config(['rivo.site.type' => 'gateway']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SiteSelect'));
    }

    public function test_invalid_deployment_type_fails_instead_of_showing_the_clinic_interface(): void
    {
        config(['rivo.site.type' => 'admin.']);

        $this->get('/')->assertInternalServerError();
    }
}
