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
 * ADR-215 — le Super Admin lit tout le Laboratoire d'un site depuis le
 * portail : les écrans du site, relayés par son API, jamais par sa base.
 */
class SiteLaboratoryPortalTest extends TestCase
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

    public function test_the_portal_lists_the_sites_without_calling_any_of_them(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/laboratory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Laboratory/Index')
                ->count('sites', 3)
                ->where('sites.1.url', '/super-admin/sites/A/laboratoire')
                ->where('sites.2.configured', false));

        Http::assertNothingSent();
    }

    public function test_the_laboratory_opens_on_the_queue_of_the_site_with_portal_addresses(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-laboratory*' => Http::response([
                'component' => 'Laboratory/Index',
                'props' => [
                    'report_url' => '/laboratory/rapports',
                    'requests' => ['next_page_url' => 'https://a.test/api/v1/super-admin/site-laboratory?page=2'],
                    'note' => 'Voir /laboratory-archives, sans lien',
                ],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/laboratoire')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Laboratory/Index')
                ->where('report_url', '/super-admin/sites/A/laboratoire/rapports')
                ->where('requests.next_page_url', '/super-admin/sites/A/laboratoire?page=2')
                ->where('note', 'Voir /laboratory-archives, sans lien')
                ->where('laboratoryContext.base', '/super-admin/sites/A/laboratoire')
                ->where('laboratoryContext.site.name', 'Ambondromamy')
                ->where('laboratoryContext.overview_url', route('super-admin.laboratory.index'))
                ->where('laboratoryContext.sites.2.configured', false)
                ->missing('pharmacyContext'));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://a.test/api/v1/super-admin/site-laboratory')
            && $request->hasHeader('Authorization', 'Bearer a-token')
            && $request->hasHeader('X-Rivo-Actor-UUID', $this->superAdmin->uuid)
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0], 'laboratory_results.view'));
    }

    public function test_only_laboratory_screens_can_be_rendered(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['component' => 'Pharmacy/Stock/Index', 'props' => []], 200)]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/laboratoire/paillasse')->assertNotFound();
    }

    public function test_a_clinical_gesture_refused_by_the_site_is_explained(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['message' => 'Ce geste se fait au laboratoire du site.'], 403)]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/sites/A/laboratoire/requests/r-1')
            ->post('/super-admin/sites/A/laboratoire/requests/r-1/receive', ['samples' => []])
            ->assertRedirect('/super-admin/sites/A/laboratoire/requests/r-1')
            ->assertSessionHas('error', 'Ce geste se fait au laboratoire du site.');
    }

    public function test_a_reference_write_follows_the_site_redirection_back_into_the_portal(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-laboratory/microbiologie/family' => Http::response([
                'redirect' => 'https://a.test/laboratory/microbiologie', 'status' => 'Famille ajoutée.', 'error' => null,
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/sites/A/laboratoire/microbiologie')
            ->post('/super-admin/sites/A/laboratoire/microbiologie/family', ['name' => 'Entérobactéries'])
            ->assertRedirect('/super-admin/sites/A/laboratoire/microbiologie')
            ->assertSessionHas('status', 'Famille ajoutée.');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key')
            && $request['name'] === 'Entérobactéries'
            && $request->header('Referer')[0] === 'https://a.test/laboratory/microbiologie');
    }

    public function test_the_portal_refuses_an_account_denied_the_laboratory(): void
    {
        $this->superAdmin->permissions()->attach(
            Permission::query()->where('name', 'laboratory_results.view')->value('id'),
            ['effect' => 'deny', 'source' => 'MANUAL'],
        );
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/laboratoire')->assertForbidden();
        $this->actingAs($this->superAdmin)->get('/super-admin/laboratory')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_site_menu_and_the_old_showcase_lead_to_the_real_laboratory(): void
    {
        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A?module=LABORATORY')
            ->assertRedirect('/super-admin/sites/A/laboratoire');
    }
}
