<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    public function test_super_admin_dashboard_lists_the_three_independent_sites(): void
    {
        $actor = $this->user('SUPER_ADMIN');

        $this->actingAs($actor)->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Dashboard')
                ->has('sites', 3)
                ->where('sites.0.name', 'Mampikony')
                ->where('sites.1.name', 'Ambondromamy')
                ->where('sites.2.name', 'Boriziny')
                ->has('modules', 14)
                ->where('modules.8.code', 'PHARMACY')
                ->where('modules.9.code', 'HR')
                ->where('modules.10.code', 'LOGISTICS')
                ->where('modules.11.code', 'GUARDING')
                ->where('modules.13.code', 'CATALOG')
                ->has('adminNavigation', 3));
    }

    public function test_each_site_exposes_all_module_navigation_without_querying_a_local_clinic_database(): void
    {
        $actor = $this->user('SUPER_ADMIN');

        foreach (['M', 'A', 'B'] as $siteCode) {
            $this->actingAs($actor)->get("/super-admin/sites/{$siteCode}?module=PHARMACY")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('SuperAdmin/Sites/Show')
                    ->where('clinic.code', $siteCode)
                    ->has('clinic.modules', 14)
                    ->where('selectedModule.code', 'PHARMACY')
                    ->where('selectedModule.areas.1', 'Lots et péremptions')
                    ->where('selectedModule.areas.3', 'Inventaires'));
        }
    }

    public function test_super_admin_can_open_every_central_workspace(): void
    {
        $actor = $this->user('SUPER_ADMIN');

        foreach (['finance', 'logistics', 'guarding', 'users', 'settings', 'audit'] as $workspace) {
            $this->actingAs($actor)->get("/super-admin/workspaces/{$workspace}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('SuperAdmin/Workspace'));
        }

        $this->actingAs($actor)->get('/super-admin/workspaces/hr')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/HumanResources/Index')
                ->has('sites', 3)
                ->where('summary.online_sites', 0));

        $this->actingAs($actor)->get('/super-admin/workspaces/tariffs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/Tariffs/Index'));

        // Roles & permissions is a real, site-scoped module (per account,
        // not per role) — never the generic placeholder.
        $this->actingAs($actor)->get('/super-admin/workspaces/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/Users/Index'));
    }

    public function test_finance_workspace_places_surgical_revenue_without_inventing_amounts(): void
    {
        $actor = $this->user('SUPER_ADMIN');

        $this->actingAs($actor)->get('/super-admin/workspaces/finance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Workspace')
                ->where('workspace.code', 'FINANCE')
                ->where('workspace.areas.2', 'Revenus chirurgie par acte')
                ->has('workspace.surgical_revenue_rows', 28)
                ->where('workspace.surgical_revenue_rows.0.name', 'Adénome prostatique')
                ->where('workspace.surgical_revenue_rows.0.actual', null)
                ->where('workspace.surgical_revenue_rows.0.unpaid_debt', null));
    }

    public function test_admin_portal_rejects_an_operational_role_at_login(): void
    {
        $user = $this->user('ADMINISTRATION');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_super_admin_explicit_deny_is_respected_by_portal_routes(): void
    {
        $actor = $this->user('SUPER_ADMIN');
        $permission = Permission::query()->where('name', 'sites.view')->firstOrFail();
        $actor->permissions()->attach($permission, ['effect' => 'deny']);

        $this->actingAs($actor->fresh())->get('/super-admin/sites/M')->assertForbidden();
    }

    public function test_invalid_site_or_module_is_not_silently_accepted(): void
    {
        $actor = $this->user('SUPER_ADMIN');

        $this->actingAs($actor)->get('/super-admin/sites/UNKNOWN')->assertNotFound();
        $this->actingAs($actor)->get('/super-admin/sites/M?module=UNKNOWN')->assertNotFound();
    }

    public function test_super_admin_routes_do_not_exist_on_a_clinic_deployment(): void
    {
        config(['rivo.site.type' => 'clinic']);

        $this->actingAs($this->user('SUPER_ADMIN'))
            ->get('/super-admin/sites/M')
            ->assertNotFound();
    }
}
