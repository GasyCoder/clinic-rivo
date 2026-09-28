<?php

namespace Tests\Feature\SuperAdmin;

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
 * ADR-211 — les Partenaires d'un site, gérés depuis le portail : l'écran du
 * site, relayé par son API, jamais par sa base (ADR-004).
 */
class SitePartnersPortalTest extends TestCase
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

    public function test_the_portal_lists_the_sites_to_open_their_partners(): void
    {
        $this->actingAs($this->superAdmin)->get('/super-admin/partners')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Partners/Index')
                ->has('sites', 3)
                ->where('sites.1.url', '/super-admin/sites/A/partenaires')
                ->where('sites.1.configured', true)
                ->where('sites.2.configured', false));
    }

    public function test_the_partners_of_a_site_open_with_portal_addresses(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-partners*' => Http::response([
                'component' => 'Partners/Index',
                'props' => [
                    'partners' => [],
                    'restore_url' => '/partenaires/abc/restore',
                ],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/partenaires')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Partners/Index')
                ->where('restore_url', '/super-admin/sites/A/partenaires/abc/restore')
                ->where('partnersContext.base', '/super-admin/sites/A/partenaires')
                ->where('partnersContext.site.name', 'Ambondromamy')
                ->where('partnersContext.overview_url', route('super-admin.partners.index')));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://a.test/api/v1/super-admin/site-partners')
            && $request->hasHeader('Authorization', 'Bearer a-token')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0], 'partner_organizations.view'));
    }

    public function test_the_partner_form_page_opens_from_the_portal(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/site-partners/nouveau*' => Http::response([
                'component' => 'Partners/Form',
                'props' => ['partner' => null, 'categories' => [], 'professions' => [], 'addresses' => []],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/partenaires/nouveau')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Partners/Form')
                ->where('partnersContext.base', '/super-admin/sites/A/partenaires'));
    }

    public function test_only_partner_screens_can_be_rendered_and_an_unconfigured_site_says_so(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['component' => 'Administration/Employees/Index', 'props' => []], 200)]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/partenaires')->assertNotFound();

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/B/partenaires')
            ->assertRedirect(route('super-admin.partners.index'))
            ->assertSessionHas('error');
    }
}
