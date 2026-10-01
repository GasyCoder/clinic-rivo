<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Support\SiteApi\RemoteActorPermissions;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-223 — le compte rendu d'analyses d'un site se règle depuis le portail, par
 * l'API du site : enregistré avec les autres paramètres, prévisualisé par le site
 * lui-même (patient fictif, rien d'enregistré), remis à zéro avec son logo.
 */
class LabReportSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/super-admin/app-settings';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AppSettings::DISK);
    }

    private function asSite(): void
    {
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
            'rivo.brand' => 'Clinique Saint Georges',
        ]);
    }

    private function asPortal(): User
    {
        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);

        return User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
    }

    /** @param list<string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }

    /** Ce que le formulaire commun envoie toujours (AppSettingsApiTest). */
    private function common(): array
    {
        return [
            'app_name' => null, 'app_tagline' => null, 'search_engines_hidden' => true, 'primary_color' => null,
            'currency_label' => 'Ar', 'currency_position' => 'after', 'currency_decimals' => 0,
            'baby_max_age' => 1, 'child_max_age' => 15,
        ];
    }

    public function test_an_unset_site_serves_the_original_report_values(): void
    {
        $this->asSite();

        $this->withHeaders($this->headers(['settings.view']))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.values.lab_report_template', 'CLASSIC')
            ->assertJsonPath('data.values.lab_report_signatory', 'LAB')
            ->assertJsonPath('data.values.lab_report_font_size', 100)
            ->assertJsonPath('data.values.lab_report_show_qr', false)
            ->assertJsonPath('data.values.lab_report_show_sent', true)
            ->assertJsonPath('data.values.lab_report_accent_color', null)
            ->assertJsonPath('data.fallbacks.lab_report.heading', 'Clinique Saint Georges')
            ->assertJsonPath('data.fallbacks.lab_report.lab_signatory', 'Le responsable du laboratoire')
            ->assertJsonPath('data.assets.lab_logo.present', false);
    }

    public function test_the_report_is_saved_with_the_other_settings(): void
    {
        $this->asSite();

        $this->withHeaders($this->headers(['settings.view', 'settings.update']))
            ->putJson(self::URL, [
                ...$this->common(),
                'lab_report_template' => 'BANNER',
                'lab_report_accent_color' => '#0f766e',
                'lab_report_signatory' => 'AUTO',
                'lab_report_website' => 'www.exemple.mg',
                'lab_report_show_qr' => true,
                'lab_report_show_generated' => false,
                'lab_report_font_size' => 110,
            ])
            ->assertOk()
            ->assertJsonPath('data.values.lab_report_template', 'BANNER')
            ->assertJsonPath('data.values.lab_report_accent_color', '#0F766E')
            ->assertJsonPath('data.values.lab_report_show_qr', true)
            ->assertJsonPath('data.values.lab_report_show_generated', false);

        $setting = AppSetting::query()->firstOrFail();
        $this->assertSame('AUTO', $setting->lab_report_signatory);
        $this->assertSame(110, $setting->lab_report_font_size);
        $this->assertSame('www.exemple.mg', $setting->lab_report_website);
    }

    public function test_an_unknown_choice_or_a_bad_colour_is_refused(): void
    {
        $this->asSite();

        $this->withHeaders($this->headers(['settings.view', 'settings.update']))
            ->putJson(self::URL, [...$this->common(), 'lab_report_template' => 'FANCY', 'lab_report_text_color' => 'rouge', 'lab_report_font_size' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lab_report_template', 'lab_report_text_color', 'lab_report_font_size']);

        $this->assertSame(0, AppSetting::query()->count());
    }

    public function test_the_site_renders_the_preview_without_saving_anything(): void
    {
        $this->asSite();

        $this->withHeaders($this->headers([]))
            ->get(self::URL.'/lab-report-preview')
            ->assertForbidden();

        $response = $this->withHeaders($this->headers(['settings.view']))
            ->get(self::URL.'/lab-report-preview?lab_report_template=MINIMAL&lab_report_show_qr=1&lab_report_signatory=BOTH');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(0, AppSetting::query()->count(), 'un aperçu n’enregistre rien');

        $this->withHeaders([...$this->headers(['settings.view']), 'Accept' => 'application/json, application/pdf'])
            ->get(self::URL.'/lab-report-preview?lab_report_accent_color=bleu')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lab_report_accent_color']);
    }

    public function test_resetting_the_settings_removes_the_report_logo(): void
    {
        $this->asSite();
        Storage::disk(AppSettings::DISK)->put('branding/lab-logo.png', 'image');
        AppSetting::query()->create(['lab_report_logo_path' => 'branding/lab-logo.png', 'lab_report_template' => 'BANNER']);

        $this->withHeaders($this->headers(['settings.view', 'settings.update']))
            ->deleteJson(self::URL.'/reset', ['confirmation' => 'RÉINITIALISER'])
            ->assertOk()
            ->assertJsonPath('data.values.lab_report_template', 'CLASSIC')
            ->assertJsonPath('data.assets.lab_logo.present', false);

        Storage::disk(AppSettings::DISK)->assertMissing('branding/lab-logo.png');
    }

    public function test_the_portal_relays_the_preview_of_a_site(): void
    {
        $admin = $this->asPortal();
        Http::fake([
            'https://a.test/api/v1/super-admin/app-settings/lab-report-preview*' => Http::response('%PDF-1.7 apercu', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $response = $this->actingAs($admin)
            ->get('/super-admin/settings/lab-report-preview?site_code=A&lab_report_template=BANNER&lab_report_show_qr=1');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-1.7 apercu', $response->getContent());

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://a.test/api/v1/super-admin/app-settings/lab-report-preview?')
            && $request['lab_report_template'] === 'BANNER'
            && (string) $request['lab_report_show_qr'] === '1'
            && ! $request->hasHeader('Idempotency-Key')
            && str_contains(RemoteActorPermissions::sent($request), 'settings.view'));

        $this->assertSame(0, AppSetting::query()->count(), 'rien n’est écrit dans la base du portail');
    }

    public function test_the_portal_itself_prints_no_report(): void
    {
        $admin = $this->asPortal();
        Http::fake();

        $this->actingAs($admin)
            ->getJson('/super-admin/settings/lab-report-preview?site_code=PORTAL')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_code']);

        Http::assertNothingSent();
    }

    public function test_a_site_that_refuses_the_preview_says_why(): void
    {
        $admin = $this->asPortal();
        Http::fake([
            'https://a.test/*' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['lab_report_font' => ['Choisissez une police proposée.']]], 422),
        ]);

        $this->actingAs($admin)
            ->getJson('/super-admin/settings/lab-report-preview?site_code=A')
            ->assertStatus(422)
            ->assertJsonPath('errors.lab_report_font.0', 'Choisissez une police proposée.');
    }
}
