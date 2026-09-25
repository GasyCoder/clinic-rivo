<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-184 — le portail règle chaque site par son API, jamais par sa base, et
 * se règle lui-même par les mêmes actions et les mêmes règles.
 */
class AppSettingsPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AppSettings::DISK);

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_the_page_lists_every_site_and_the_portal_itself(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/app-settings' => Http::response(['data' => $this->sitePayload('M', 'Mampikony')]),
            'https://a.test/api/v1/super-admin/app-settings' => Http::response(['message' => 'Hors ligne'], 503),
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Settings/Index')
                ->has('targets', 3)
                ->where('targets.0.site.code', 'M')
                ->where('targets.0.ok', true)
                ->where('targets.1.ok', false)
                ->where('targets.2.site.code', 'PORTAL')
                ->where('targets.2.kind', 'portal')
                ->where('targets.2.data.configured', false)
                ->where('limits.child_max_age', 20));

        Http::assertSent(fn ($request) => str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'settings.view'));
    }

    public function test_the_former_workspace_address_leads_to_the_settings(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/settings')
            ->assertRedirect('/super-admin/settings');
    }

    public function test_a_site_is_set_through_its_api_only(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/app-settings' => Http::response([
                'message' => 'Paramètres de l’application enregistrés pour ce site.',
                'data' => $this->sitePayload('A', 'Ambondromamy'),
            ]),
        ]);

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/settings', [...$this->valid(), 'site_code' => 'A'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Paramètres de l’application enregistrés pour ce site.');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://a.test/api/v1/super-admin/app-settings'
            && $request->hasHeader('Idempotency-Key')
            && $request['child_max_age'] === 12
            && $request['search_engines_hidden'] === true
            && $request['app_tagline'] === 'Ny fahasalamana no loharanon-karena'
            && ! isset($request['site_code'])
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'settings.update'));

        // Rien n'est écrit dans la base du portail pour un site.
        $this->assertSame(0, AppSetting::query()->count());
    }

    public function test_invalid_bands_are_refused_before_anything_is_sent(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/settings', [...$this->valid(), 'site_code' => 'A', 'baby_max_age' => 4, 'child_max_age' => 4])
            ->assertSessionHasErrors('child_max_age');

        Http::assertNothingSent();
    }

    public function test_the_portal_sets_its_own_settings_locally_and_audits_them(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/settings', [...$this->valid(), 'site_code' => 'PORTAL'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Paramètres du portail enregistrés.');

        Http::assertNothingSent();
        $this->assertSame('Portail RIVO', AppSetting::query()->sole()->app_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'app_settings.update', 'user_id' => $this->superAdmin->id]);
    }

    public function test_a_file_travels_to_the_site_as_is(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/app-settings/assets/logo' => Http::response([
                'message' => 'Logo enregistré pour ce site.',
                'data' => $this->sitePayload('M', 'Mampikony'),
            ]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/settings/assets/logo', ['site_code' => 'M', 'file' => UploadedFile::fake()->image('logo.png', 300, 100)])
            ->assertRedirect()
            ->assertSessionHas('status', 'Logo enregistré pour ce site.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/app-settings/assets/logo'
            && $request->isMultipart());
    }

    public function test_the_portal_keeps_its_own_signature_off_any_public_address(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/super-admin/settings/assets/signature', ['site_code' => 'PORTAL', 'file' => UploadedFile::fake()->image('sig.png', 200, 80)])
            ->assertRedirect();

        $this->assertNotNull(AppSetting::query()->sole()->signature_path);
        $this->get('/branding/signature')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'app_name' => 'Portail RIVO',
            'app_tagline' => 'Ny fahasalamana no loharanon-karena',
            'search_engines_hidden' => true,
            'primary_color' => '#0F766E',
            'currency_label' => 'Ar',
            'currency_position' => 'after',
            'currency_decimals' => 0,
            'baby_max_age' => 1,
            'child_max_age' => 12,
            'director_name' => null,
            'director_title' => null,
            'legal_nif' => null,
            'legal_stat' => null,
            'legal_address' => null,
            'legal_phone' => null,
            'legal_email' => null,
            'bank_name' => null,
            'bank_account' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function sitePayload(string $code, string $name): array
    {
        return [
            'site' => ['code' => $code, 'name' => $name, 'type' => 'clinic'],
            'configured' => false,
            'values' => ['currency_label' => 'Ar', 'currency_position' => 'after', 'currency_decimals' => 0, 'baby_max_age' => 1, 'child_max_age' => 15],
            'fallbacks' => ['app_name' => 'Clinique Saint Georges'],
            'assets' => ['logo' => ['present' => false, 'data_url' => null], 'icon' => ['present' => false, 'data_url' => null], 'signature' => ['present' => false, 'data_url' => null]],
            'updated_at' => null,
            'updated_by' => null,
        ];
    }
}
