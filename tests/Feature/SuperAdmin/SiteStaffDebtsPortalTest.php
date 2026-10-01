<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\SiteApi\RemoteActorPermissions;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-229 — les dettes du personnel vivent dans Finance, au portail : un aperçu de tous
 * les sites lu par leur API, puis les écrans d'un site relayés. Plus rien dans les RH.
 */
class SiteStaffDebtsPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'url' => 'https://m.test', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
                ['code' => 'B', 'name' => 'Boriziny', 'url' => 'https://b.test', 'api_url' => null, 'api_token' => null],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
        $this->withoutVite();
    }

    public function test_finance_shows_every_site_from_its_api(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/staff-debts/overview' => Http::response(['data' => [
                'counts' => ['a-decider' => 2, 'a-verser' => 1, 'en-cours' => 3, 'closes' => 4],
                'balance' => '450000.00', 'arrears' => '20000.00', 'late' => 1,
                'to_disburse_amount' => '100000.00', 'requested_amount' => '300000.00', 'interest' => '15000.00',
                'rules' => ['configured' => true, 'requests_open' => true, 'has_interest' => true],
            ], 'meta' => ['site' => 'A']], 200),
            'https://m.test/*' => Http::response(['message' => 'Indisponible'], 503),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/finance/dettes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Finance/StaffDebts')
                ->count('sites', 3)
                ->where('sites.1.url', '/super-admin/sites/A/finance/dettes')
                ->where('sites.1.ok', true)
                ->where('sites.1.overview.counts.a-decider', 2)
                ->where('sites.1.overview.balance', '450000.00')
                ->where('sites.0.ok', false)
                ->where('sites.0.overview', null)
                ->where('sites.2.configured', false)
                ->where('can.settings', true));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://a.test/api/v1/super-admin/staff-debts/overview'
            && $request->hasHeader('Authorization', 'Bearer a-token'));
    }

    public function test_a_site_opens_its_list_with_portal_addresses_and_the_finance_bar(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-staff-debts*' => Http::response([
                'component' => 'Finance/StaffDebts/Index',
                'props' => [
                    'listing' => ['debts' => [['url' => '/finance/dettes/d-1']]],
                    'export_url' => '/finance/dettes/export',
                ],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/finance/dettes?vue=a-verser')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/StaffDebts/Index')
                ->where('listing.debts.0.url', '/super-admin/sites/A/finance/dettes/d-1')
                ->where('export_url', '/super-admin/sites/A/finance/dettes/export')
                ->where('staffDebtContext.base', '/super-admin/sites/A/finance/dettes')
                ->where('staffDebtContext.site.name', 'Ambondromamy')
                ->where('staffDebtContext.overview_url', route('super-admin.finance.staff-debts.index'))
                ->missing('hrContext'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://a.test/api/v1/super-admin/site-staff-debts?')
            && $request['vue'] === 'a-verser'
            && $request->hasHeader('X-Rivo-Actor-UUID', $this->superAdmin->uuid)
            && str_contains(RemoteActorPermissions::sent($request), 'staff_debts.settings'));
    }

    public function test_only_finance_debt_screens_are_rendered(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['component' => 'Administration/Employees/Index', 'props' => []], 200)]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/finance/dettes')->assertNotFound();
    }

    public function test_a_decision_follows_the_site_redirection_back_into_finance(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-staff-debts/d-1/accorder' => Http::response([
                'redirect' => '/finance/dettes/d-1', 'status' => 'Dette DP-000001 accordée à RABE Vola : elle est à verser.', 'error' => null,
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/sites/A/finance/dettes/d-1')
            ->post('/super-admin/sites/A/finance/dettes/d-1/accorder', ['amount' => '100000', 'installment_amount' => '50000', 'first_period' => '2026-10', 'repayment_mode' => 'SALARY'])
            ->assertRedirect('/super-admin/sites/A/finance/dettes/d-1')
            ->assertSessionHas('status', 'Dette DP-000001 accordée à RABE Vola : elle est à verser.');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key')
            && $request['repayment_mode'] === 'SALARY'
            && $request->header('Referer')[0] === 'https://a.test/finance/dettes/d-1');
    }

    public function test_the_old_hr_address_leads_to_finance(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/rh/dettes')
            ->assertRedirect('/super-admin/sites/A/finance/dettes');
        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/rh/dettes/d-1?vue=en-cours')
            ->assertRedirect('/super-admin/sites/A/finance/dettes/d-1?vue=en-cours');

        Http::assertNothingSent();
    }

    public function test_an_account_denied_the_debts_is_refused(): void
    {
        $this->superAdmin->permissions()->attach(
            Permission::query()->where('name', 'staff_debts.view')->value('id'),
            ['effect' => 'deny', 'source' => 'MANUAL'],
        );
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/finance/dettes')->assertForbidden();
        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/finance/dettes')->assertForbidden();

        Http::assertNothingSent();
    }
}
