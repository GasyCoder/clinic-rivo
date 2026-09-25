<?php

namespace Tests\Feature\Settings;

use App\Enums\AuthTemplate;
use App\Enums\ProfileTemplate;
use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-184 — les modèles des pages d'authentification et de « Mon profil », et
 * l'image de fond de la connexion, réglés par site depuis le portail. Un site
 * que personne n'a réglé garde « Couverture » et l'image de la clinique.
 */
class ScreenTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/super-admin/app-settings';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AppSettings::DISK);

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
            'rivo.auth_cover_url' => '/images/brand/clinic-saint-georges-cover.jpg',
        ]);
    }

    public function test_an_unconfigured_site_keeps_the_cover_template_and_the_clinic_image(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('site.authTemplate', 'COVER')
            ->where('site.profileTemplate', 'SIDEBAR')
            ->where('site.authCoverUrl', '/images/brand/clinic-saint-georges-cover.jpg'));
    }

    public function test_the_templates_set_for_the_site_reach_every_auth_page(): void
    {
        AppSetting::query()->create(['auth_template' => AuthTemplate::Split->value, 'profile_template' => ProfileTemplate::Banner->value]);

        foreach (['/login' => 'Auth/Login', '/forgot-password' => 'Auth/ForgotPassword'] as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
                ->component($component)
                ->where('site.authTemplate', 'SPLIT')
                ->where('site.profileTemplate', 'BANNER'));
        }
    }

    public function test_an_unknown_template_falls_back_instead_of_breaking_the_login(): void
    {
        AppSetting::query()->create(['auth_template' => 'NEON', 'profile_template' => 'GRID']);

        $settings = app(AppSettings::class);
        $this->assertSame(AuthTemplate::Cover, $settings->authTemplate());
        $this->assertSame(ProfileTemplate::Sidebar, $settings->profileTemplate());
    }

    public function test_templates_are_set_through_the_site_api_and_validated(): void
    {
        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->putJson(self::URL, [...$this->valid(), 'auth_template' => 'CENTERED', 'profile_template' => 'BANNER'])
            ->assertOk()
            ->assertJsonPath('data.values.auth_template', 'CENTERED')
            ->assertJsonPath('data.values.profile_template', 'BANNER');

        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::URL, [...$this->valid(), 'auth_template' => 'NEON', 'profile_template' => 'GRID'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auth_template', 'profile_template']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'app_settings.update']);
    }

    public function test_the_background_is_served_publicly_and_previewed_small(): void
    {
        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->post(self::URL.'/assets/background', ['file' => UploadedFile::fake()->image('fond.jpg', 1920, 1080)])
            ->assertOk()
            ->assertJsonPath('message', 'Image de fond enregistrée pour ce site.')
            ->assertJsonPath('data.assets.background.present', true);

        $settings = app(AppSettings::class);
        $settings->forget();

        // L'aperçu envoyé au portail est réduit, jamais la photo entière.
        $preview = $settings->assetPreviewDataUri('background');
        $this->assertStringStartsWith('data:image/jpeg;base64,', $preview);
        $this->assertSame(640, getimagesizefromstring(base64_decode(Str::after($preview, ',')))[0]);

        $url = $settings->authBackgroundUrl();
        $this->assertStringStartsWith('/branding/background?v=', $url);
        $this->get($url)->assertOk();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('site.authCoverUrl', $url));
    }

    public function test_a_removed_background_falls_back_to_the_clinic_image(): void
    {
        $headers = $this->headers(['settings.update']);
        $this->withHeaders($headers)->post(self::URL.'/assets/background', ['file' => UploadedFile::fake()->image('fond.jpg', 800, 450)])->assertOk();
        $this->withHeaders($this->headers(['settings.update']))
            ->deleteJson(self::URL.'/assets/background')
            ->assertOk()
            ->assertJsonPath('message', 'Image de fond retirée pour ce site.');

        $settings = app(AppSettings::class);
        $settings->forget();
        $this->assertSame('/images/brand/clinic-saint-georges-cover.jpg', $settings->authBackgroundUrl());
        $this->get('/branding/background')->assertNotFound();
    }

    public function test_a_site_not_up_to_date_says_so_instead_of_a_raw_sql_error(): void
    {
        Schema::table('app_settings', fn ($table) => $table->dropColumn('profile_template'));

        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::URL, $this->valid())
            ->assertUnprocessable()
            ->assertJsonPath('errors.site_code.0', AppSettings::NOT_INSTALLED_MESSAGE);
    }

    public function test_an_oversized_background_is_refused(): void
    {
        $this->withHeaders($this->headers(['settings.update']))
            ->post(self::URL.'/assets/background', ['file' => UploadedFile::fake()->image('fond.jpg')->size(3000)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'currency_label' => 'Ar',
            'currency_position' => 'after',
            'currency_decimals' => 0,
            'baby_max_age' => 1,
            'child_max_age' => 15,
        ];
    }

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
}
