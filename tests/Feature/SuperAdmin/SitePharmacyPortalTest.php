<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-189 — le Super Admin voit toute la Pharmacie d'un site depuis le
 * portail : les écrans du site, relayés par son API, jamais par sa base.
 */
class SitePharmacyPortalTest extends TestCase
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
    }

    public function test_the_pharmacy_opens_on_the_stock_of_the_site_with_portal_addresses(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-pharmacy/stock*' => Http::response([
                'component' => 'Pharmacy/Stock/Index',
                'props' => [
                    'purchases_url' => '/pharmacy/purchase-orders',
                    'medicines' => ['next_page_url' => 'https://a.test/api/v1/super-admin/site-pharmacy/stock?page=2'],
                    'note' => 'Voir /pharmacy-historique, sans lien',
                ],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/pharmacie')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pharmacy/Stock/Index')
                ->where('purchases_url', '/super-admin/sites/A/pharmacie/purchase-orders')
                ->where('medicines.next_page_url', '/super-admin/sites/A/pharmacie/stock?page=2')
                ->where('note', 'Voir /pharmacy-historique, sans lien')
                ->where('pharmacyContext.base', '/super-admin/sites/A/pharmacie')
                ->where('pharmacyContext.site.name', 'Ambondromamy')
                ->where('pharmacyContext.overview_url', route('super-admin.stock.index'))
                ->where('pharmacyContext.sites.2.configured', false)
                ->missing('hrContext'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://a.test/api/v1/super-admin/site-pharmacy/stock')
            && $request->hasHeader('Authorization', 'Bearer a-token')
            && $request->hasHeader('X-Rivo-Actor-UUID', $this->superAdmin->uuid)
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0], 'stock.view'));
    }

    public function test_only_pharmacy_screens_can_be_rendered(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['component' => 'Administration/Employees/Index', 'props' => []], 200)]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/pharmacie/stock')->assertNotFound();
    }

    public function test_a_physical_act_refused_by_the_site_is_explained(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['message' => 'Ce geste se fait à la Pharmacie du site.'], 403)]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/sites/A/pharmacie/stock')
            ->post('/super-admin/sites/A/pharmacie/stock/inventory', ['reason' => 'Comptage'])
            ->assertRedirect('/super-admin/sites/A/pharmacie/stock')
            ->assertSessionHas('error', 'Ce geste se fait à la Pharmacie du site.');
    }

    public function test_a_write_follows_the_site_redirection_back_into_the_portal(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-pharmacy/setup/suppliers' => Http::response([
                'redirect' => 'https://a.test/pharmacy/suppliers/s-9', 'status' => 'Dossier du fournisseur Arbiochem créé.', 'error' => null,
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/sites/A/pharmacie/suppliers')
            ->post('/super-admin/sites/A/pharmacie/setup/suppliers', ['code' => 'ABC', 'name' => 'Arbiochem'])
            ->assertRedirect('/super-admin/sites/A/pharmacie/suppliers/s-9')
            ->assertSessionHas('status', 'Dossier du fournisseur Arbiochem créé.');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key')
            && $request['code'] === 'ABC'
            && $request->header('Referer')[0] === 'https://a.test/pharmacy/suppliers');
    }

    public function test_the_portal_refuses_an_account_denied_the_pharmacy(): void
    {
        $this->superAdmin->permissions()->attach(
            Permission::query()->where('name', 'pharmacy.view')->value('id'),
            ['effect' => 'deny', 'source' => 'MANUAL'],
        );
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/pharmacie')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_site_menu_and_the_old_showcase_lead_to_the_real_pharmacy(): void
    {
        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A?module=PHARMACY')
            ->assertRedirect('/super-admin/sites/A/pharmacie');
    }
}
