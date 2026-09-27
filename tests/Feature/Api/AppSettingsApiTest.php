<?php

namespace Tests\Feature\Api;

use App\Models\AppSetting;
use App\Models\DiscountCoupon;
use App\Models\SiteMaintenance;
use App\Services\Settings\AppSettings;
use App\Services\Settings\ThemeColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-184 — les paramètres de l'application d'un site, réglés depuis le portail
 * par l'API du site : réautorisés localement, validés, audités avec l'identité
 * centrale ; le logo et l'icône servis sans connexion, la signature jamais.
 */
class AppSettingsApiTest extends TestCase
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
            'rivo.brand' => 'Clinique Saint Georges',
            'rivo.documents.nif' => 'NIF-CONFIG',
        ]);
    }

    public function test_an_unconfigured_site_keeps_its_deployment_configuration(): void
    {
        $this->withHeaders($this->headers(['settings.view']))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.values.currency_label', 'Ar')
            ->assertJsonPath('data.values.baby_max_age', 1)
            ->assertJsonPath('data.values.child_max_age', 15)
            ->assertJsonPath('data.fallbacks.app_name', 'Clinique Saint Georges')
            ->assertJsonPath('data.values.search_engines_hidden', true)
            ->assertJsonPath('data.fallbacks.legal_nif', 'NIF-CONFIG');

        $settings = app(AppSettings::class);
        $this->assertSame('Clinique Saint Georges', $settings->brand());
        $this->assertSame('NIF-CONFIG', $settings->documents()['nif']);
        // ADR-191 — aucune couleur redéfinie : seules les règles du contraste renforcé, que chacun peut choisir.
        $this->assertStringStartsWith('html[data-contrast="high"]{', $settings->themeCss());
    }

    public function test_reading_and_writing_each_need_their_own_permission(): void
    {
        $this->withHeaders($this->headers([]))->getJson(self::URL)->assertForbidden();

        $this->withHeaders($this->headers(['settings.view']))
            ->putJson(self::URL, $this->valid())
            ->assertForbidden();

        $this->withHeaders($this->headers(['settings.view']))
            ->deleteJson(self::URL.'/reset', ['confirmation' => 'RÉINITIALISER'])
            ->assertForbidden();

        $this->assertSame(0, AppSetting::query()->count());
    }

    public function test_settings_are_saved_in_place_applied_and_audited_with_the_central_actor(): void
    {
        $actor = (string) Str::uuid();

        $this->withHeaders($this->headers(['settings.update', 'settings.view'], $actor))
            ->putJson(self::URL, $this->valid())
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.values.app_name', 'Clinique Saint Georges Ambondromamy')
            ->assertJsonPath('data.values.currency_label', 'Ariary')
            ->assertJsonPath('data.values.app_tagline', 'Votre santé, notre priorité')
            ->assertJsonPath('data.values.search_engines_hidden', false)
            ->assertJsonPath('data.updated_by', 'Direction centrale');

        $this->withHeaders($this->headers(['settings.update'], $actor))
            ->putJson(self::URL, [...$this->valid(), 'legal_nif' => '', 'child_max_age' => 14])
            ->assertOk();

        $this->assertSame(1, AppSetting::query()->count());
        $setting = AppSetting::query()->firstOrFail();
        $this->assertSame(14, $setting->child_max_age);
        $this->assertNull($setting->legal_nif, 'Un champ vidé redevient la configuration du site.');
        $this->assertSame($actor, $setting->external_updated_by_uuid);

        $this->assertDatabaseHas('audit_logs', ['action' => 'app_settings.update', 'external_actor_uuid' => $actor]);

        $settings = app(AppSettings::class);
        $settings->forget();
        $this->assertSame('Clinique Saint Georges Ambondromamy', $settings->brand());
        $this->assertSame('Votre santé, notre priorité', $settings->tagline());
        $this->assertFalse($settings->hiddenFromSearchEngines());
        $this->assertSame('NIF-CONFIG', $settings->documents()['nif']);
        $this->assertSame('BNI Madagascar', $settings->documents()['bank_name']);
        $this->assertSame(['label' => 'Ariary', 'position' => 'before', 'decimals' => 2], $settings->currency());
        $this->assertSame(['baby_max_age' => 1, 'child_max_age' => 14], $settings->ageBands());
        $this->assertSame('Ariary 12 500,00', str_replace("\u{202F}", ' ', $settings->formatMoney(12500)));
    }

    public function test_a_site_whose_migration_is_not_run_says_so_instead_of_a_raw_sql_error(): void
    {
        Schema::drop('app_settings');

        // Lire reste possible : tout retombe sur la configuration.
        $this->withHeaders($this->headers(['settings.view']))->getJson(self::URL)->assertOk();

        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::URL, $this->valid())
            ->assertUnprocessable()
            ->assertJsonPath('errors.site_code.0', AppSettings::NOT_INSTALLED_MESSAGE);

        $this->withHeaders($this->headers(['settings.update']))
            ->post(self::URL.'/assets/logo', ['file' => UploadedFile::fake()->image('logo.png', 300, 100)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', AppSettings::NOT_INSTALLED_MESSAGE);

        $this->withHeaders($this->headers(['settings.update']))
            ->deleteJson(self::URL.'/reset', ['confirmation' => 'RÉINITIALISER'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.site_code.0', AppSettings::NOT_INSTALLED_MESSAGE);

        Storage::disk(AppSettings::DISK)->assertDirectoryEmpty('branding');
    }

    public function test_invalid_values_are_refused_with_a_reason(): void
    {
        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::URL, [
                ...$this->valid(),
                'primary_color' => 'bleu',
                'currency_label' => 'EUR',
                'baby_max_age' => 3,
                'child_max_age' => 2,
                'legal_email' => 'pas-un-email',
                'app_tagline' => str_repeat('a', 151),
                'search_engines_hidden' => 'peut-être',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_color', 'currency_label', 'child_max_age', 'legal_email', 'app_tagline', 'search_engines_hidden']);

        $this->assertSame(0, AppSetting::query()->count());
    }

    public function test_the_primary_color_becomes_a_light_and_dark_theme(): void
    {
        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::URL, [...$this->valid(), 'primary_color' => '#287D9F'])
            ->assertOk();

        $settings = app(AppSettings::class);
        $settings->forget();
        $css = $settings->themeCss();

        $this->assertStringContainsString(':root{--primary:197 60% 39%;', $css);
        $this->assertStringContainsString('.dark{--primary:', $css);
        $this->assertStringContainsString('--primary-foreground:0 0% 100%', $css);

        // Une couleur trop claire garde un texte lisible : foncé, jamais blanc.
        $this->assertSame('215 40% 10%', ThemeColor::fromHex('#FDE047')->lightForeground());

        $this->get('/login')->assertOk()->assertSee('<style id="rivo-theme">', false);
    }

    public function test_the_logo_is_served_publicly_and_the_signature_never_is(): void
    {
        $headers = $this->headers(['settings.update', 'settings.view']);

        $this->withHeaders($headers)
            ->post(self::URL.'/assets/logo', ['file' => UploadedFile::fake()->image('logo.png', 300, 100)])
            ->assertOk()
            ->assertJsonPath('data.assets.logo.present', true);

        $this->withHeaders($this->headers(['settings.update', 'settings.view']))
            ->post(self::URL.'/assets/signature', ['file' => UploadedFile::fake()->image('signature.png', 200, 80)])
            ->assertOk()
            ->assertJsonPath('data.assets.signature.present', true);

        $settings = app(AppSettings::class);
        $settings->forget();

        $this->assertStringStartsWith('/branding/logo?v=', $settings->logoUrl());
        $this->get($settings->logoUrl())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/branding/signature')->assertNotFound();
        $this->assertStringStartsWith('data:image/png;base64,', $settings->signatureDataUri());

        $this->assertDatabaseHas('audit_logs', ['action' => 'app_settings.asset.update']);
    }

    public function test_a_replaced_file_is_deleted_and_a_removed_one_falls_back(): void
    {
        $this->withHeaders($this->headers(['settings.update']))
            ->post(self::URL.'/assets/icon', ['file' => UploadedFile::fake()->image('icon.png', 64, 64)])
            ->assertOk();
        $first = AppSetting::query()->value('icon_path');

        $this->withHeaders($this->headers(['settings.update']))
            ->post(self::URL.'/assets/icon', ['file' => UploadedFile::fake()->image('icon2.png', 64, 64)])
            ->assertOk();
        $second = AppSetting::query()->value('icon_path');

        $this->assertNotSame($first, $second);
        Storage::disk(AppSettings::DISK)->assertMissing($first);
        Storage::disk(AppSettings::DISK)->assertExists($second);

        $this->withHeaders($this->headers(['settings.update']))
            ->deleteJson(self::URL.'/assets/icon')
            ->assertOk()
            ->assertJsonPath('data.assets.icon.present', false);

        Storage::disk(AppSettings::DISK)->assertMissing($second);
        $this->assertDatabaseHas('audit_logs', ['action' => 'app_settings.asset.remove']);
    }

    public function test_all_settings_and_assets_can_be_reset_to_their_defaults(): void
    {
        $actor = (string) Str::uuid();
        $coupon = DiscountCoupon::query()->create([
            'code' => 'GARDE26',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
        ]);
        $maintenance = SiteMaintenance::query()->create([
            'title' => 'Maintenance programmée',
            'message' => 'Ne doit pas être retirée par les paramètres.',
            'starts_at' => now()->addDay(),
        ]);
        DB::table('patient_number_sequences')->insert(['year' => now()->year, 'next_number' => 42]);

        $assets = [
            'logo_path' => 'branding/logo-custom.png',
            'icon_path' => 'branding/icon-custom.png',
            'signature_path' => 'branding/signature-custom.png',
            'auth_background_path' => 'branding/background-custom.png',
            'badge_logo_path' => 'branding/badge-custom.png',
        ];

        foreach ($assets as $path) {
            Storage::disk(AppSettings::DISK)->put($path, 'image');
        }

        AppSetting::query()->create([
            'app_name' => 'Nom personnalisé',
            'app_tagline' => 'Devise personnalisée',
            'primary_color' => '#0F766E',
            'theme_preset' => 'forest',
            'ui_density' => 'compact',
            'patient_number_prefix' => 'PAT',
            'employee_number_prefix' => 'RH',
            'auth_template' => 'SPLIT',
            'profile_template' => 'BANNER',
            'badge_tagline' => 'Badge personnalisé',
            'currency_label' => 'Ariary',
            'currency_position' => 'before',
            'currency_decimals' => 2,
            'baby_max_age' => 2,
            'child_max_age' => 16,
            'director_name' => 'Direction personnalisée',
            'legal_nif' => 'NIF-PERSONNALISE',
            'staff_discount_type' => 'PERCENT',
            'staff_discount_value' => 10,
            'search_engines_hidden' => false,
            ...$assets,
        ]);

        $this->withHeaders($this->headers(['settings.update', 'settings.view'], $actor))
            ->deleteJson(self::URL.'/reset', ['confirmation' => 'RÉINITIALISER'])
            ->assertOk()
            ->assertJsonPath('data.values.app_name', null)
            ->assertJsonPath('data.values.app_tagline', null)
            ->assertJsonPath('data.assets.logo.present', false)
            ->assertJsonPath('data.assets.icon.present', false)
            ->assertJsonPath('data.assets.signature.present', false)
            ->assertJsonPath('data.assets.background.present', false)
            ->assertJsonPath('data.assets.badge.present', false)
            ->assertJsonPath('data.values.primary_color', null)
            ->assertJsonPath('data.values.currency_label', 'Ar')
            ->assertJsonPath('data.values.child_max_age', 15);

        $this->assertSame(0, AppSetting::query()->count(), 'Aucune personnalisation ne doit subsister après le retour aux valeurs du déploiement.');
        $this->assertTrue(DiscountCoupon::query()->whereKey($coupon->getKey())->exists());
        $this->assertTrue(SiteMaintenance::query()->whereKey($maintenance->getKey())->exists());
        $this->assertSame(42, DB::table('patient_number_sequences')->where('year', now()->year)->value('next_number'));

        foreach ($assets as $path) {
            Storage::disk(AppSettings::DISK)->assertMissing($path);
        }
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'app_settings.reset',
            'external_actor_uuid' => $actor,
        ]);
    }

    public function test_reset_requires_the_exact_confirmation_text(): void
    {
        AppSetting::query()->create(['app_name' => 'À conserver']);

        foreach (['', 'réinitialiser', 'REINITIALISER', 'RÉINITIALISER!'] as $confirmation) {
            $this->withHeaders($this->headers(['settings.update']))
                ->deleteJson(self::URL.'/reset', ['confirmation' => $confirmation])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('confirmation');
        }

        $this->assertSame('À conserver', AppSetting::query()->sole()->app_name);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'app_settings.reset']);
    }

    public function test_an_svg_or_oversized_file_is_refused(): void
    {
        $this->withHeaders($this->headers(['settings.update']))
            ->post(self::URL.'/assets/logo', ['file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->withHeaders($this->headers(['settings.update']))
            ->post(self::URL.'/assets/signature', ['file' => UploadedFile::fake()->image('big.png')->size(900)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, AppSetting::query()->count());
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'app_name' => 'Clinique Saint Georges Ambondromamy',
            'app_tagline' => 'Votre santé, notre priorité',
            'search_engines_hidden' => false,
            'primary_color' => null,
            'currency_label' => 'Ariary',
            'currency_position' => 'before',
            'currency_decimals' => 2,
            'baby_max_age' => 1,
            'child_max_age' => 15,
            'director_name' => 'Dr Rakoto Jean',
            'director_title' => 'Directeur général',
            'legal_nif' => '4000123456',
            'legal_stat' => '86101 31 2020 0 00123',
            'legal_address' => 'Lot II A 12, Ambondromamy',
            'legal_phone' => '034 00 000 00',
            'legal_email' => 'contact@exemple.mg',
            'bank_name' => 'BNI Madagascar',
            'bank_account' => '00005 00001 12345678901 23',
        ];
    }

    private function headers(array $permissions, ?string $actorUuid = null): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }
}
