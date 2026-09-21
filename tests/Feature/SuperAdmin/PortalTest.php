<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            // Des URL d'API explicites : sans elles, les trois sites sont
            // « à configurer » et aucune requête n'est émise — ce qui rendrait
            // le test du tableau de bord (ADR-102) muet.
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
                ['code' => 'B', 'name' => 'Boriziny', 'api_url' => 'https://b.test/api/v1', 'api_token' => 'b-token'],
            ],
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

    /**
     * Le tableau de bord central (ADR-102) : il lit chaque site par son API,
     * et un site injoignable n'empêche ni la page de s'afficher, ni les
     * autres d'être comptés.
     */
    public function test_the_dashboard_reads_each_site_and_survives_one_being_down(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/reports/overview*' => Http::response([
                'data' => [
                    'generated_at' => now()->toIso8601String(),
                    'range' => ['days' => 30, 'from' => '2026-08-18', 'to' => '2026-09-16'],
                    'sections' => [
                        'activity' => [
                            'available' => true,
                            'today' => ['episodes' => 4, 'emergencies' => 1, 'new_patients' => 2],
                            'open_episodes' => 6,
                            'total_patients' => 120,
                            'trend' => ['dates' => ['2026-09-15', '2026-09-16'], 'series' => [
                                ['key' => 'episodes', 'label' => 'Passages', 'tone' => 'navy', 'total' => 7, 'values' => [3, 4]],
                            ]],
                            'demographics' => null,
                        ],
                        'finance' => ['available' => false, 'reason' => 'Il manque la permission « Voir la facturation ».'],
                        'clinical' => ['available' => true, 'destinations' => []],
                        'pharmacy' => ['available' => true, 'medicines' => 12, 'lots' => 20, 'expiring_soon' => 2, 'expired' => 0],
                        'people' => ['available' => true, 'employees' => 8, 'pending_leaves' => 1, 'accounts' => 5],
                    ],
                ],
                'meta' => ['site' => ['code' => 'M', 'name' => 'Mampikony']],
            ], 200),
            'https://a.test/api/v1/super-admin/reports/overview*' => Http::response(['message' => 'Site indisponible.'], 503),
            '*' => Http::response(['message' => 'Site indisponible.'], 503),
        ]);

        $this->actingAs($this->user('SUPER_ADMIN'))
            ->get('/?days=30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Dashboard')
                ->where('days', 30)
                ->has('reports', 3)
                ->where('reports.0.ok', true)
                ->where('reports.0.data.sections.activity.today.episodes', 4)
                // Une section refusée revient indisponible, jamais à zéro.
                ->where('reports.0.data.sections.finance.available', false)
                ->where('reports.1.ok', false));
    }

    /**
     * Une fenêtre hors bornes est ramenée **avant** l'appel : envoyée telle
     * quelle, elle était refusée par chaque site et les trois rapports
     * revenaient « injoignable » pour une faute de saisie.
     */
    public function test_an_out_of_range_window_is_clamped_before_the_call(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Site indisponible.'], 503)]);

        $this->actingAs($this->user('SUPER_ADMIN'))
            ->get('/?days=5000')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('days', 90));

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'days=5000')
            && (! str_contains($request->url(), 'reports/overview') || str_contains($request->url(), 'days=90')));
    }

    public function test_super_admin_can_open_every_central_workspace(): void
    {
        $actor = $this->user('SUPER_ADMIN');

        foreach (['finance', 'logistics', 'guarding', 'settings', 'audit'] as $workspace) {
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

        // Comptes et rôles sont deux écrans réels et site par site
        // (ADR-100) — jamais l'espace générique de présentation.
        $this->actingAs($actor)->get('/super-admin/workspaces/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/Users/Index'));

        $this->actingAs($actor)->get('/super-admin/workspaces/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SuperAdmin/Roles/Index'));
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
                ->has('workspace.surgical_revenue_rows', 39)
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
