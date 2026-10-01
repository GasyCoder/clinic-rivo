<?php

namespace Tests\Feature\Gateway;

use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-184 (amendement du 2026-10-02) — la passerelle affiche le logo réglé sur
 * chaque site et le logo central du portail, lus par HTTP, jamais par une base.
 */
class GatewayBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deployment_serves_its_public_identity_without_any_session(): void
    {
        config(['rivo.brand' => 'Clinique Saint Georges', 'rivo.documents.logo_url' => '/images/brand/clinic-saint-georges-logo.png']);

        $this->getJson('/branding/identity')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('brand', 'Clinique Saint Georges')
            ->assertJsonPath('logo_url', url('/images/brand/clinic-saint-georges-logo.png'))
            ->assertJsonPath('custom_logo', false)
            ->assertJsonPath('icon_url', null)
            ->assertJsonPath('maintenance', false)
            ->assertJsonMissingPath('signature_url');
    }

    public function test_the_identity_names_a_logo_set_in_the_settings(): void
    {
        Storage::fake(AppSettings::DISK)->put('branding/logo-x.png', 'png');
        AppSetting::query()->create(['app_name' => 'Saint Georges Ambondromamy', 'logo_path' => 'branding/logo-x.png']);

        $response = $this->getJson('/branding/identity')->assertOk();

        $this->assertSame('Saint Georges Ambondromamy', $response->json('brand'));
        $this->assertTrue($response->json('custom_logo'));
        $this->assertStringStartsWith(url('/branding/logo?v='), $response->json('logo_url'));
    }

    public function test_the_gateway_reads_each_site_and_the_portal_after_the_page_is_shown(): void
    {
        config([
            'rivo.site.type' => 'gateway',
            'rivo.admin_url' => 'https://app.rivo.test',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'url' => 'https://m.rivo.test'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.rivo.test'],
                ['code' => 'B', 'name' => 'Boriziny', 'url' => 'https://b.rivo.test'],
            ],
        ]);

        Http::fake([
            'https://a.rivo.test/branding/identity' => Http::response([
                'brand' => 'Clinique Saint Georges', 'logo_url' => 'https://a.rivo.test/branding/logo?v=12',
                'custom_logo' => true, 'icon_url' => 'https://elsewhere.test/icon.png', 'maintenance' => false,
            ]),
            'https://m.rivo.test/branding/identity' => Http::response([
                'brand' => 'Clinique Saint Georges', 'logo_url' => 'https://m.rivo.test/images/brand/logo.png',
                'custom_logo' => false, 'icon_url' => null, 'maintenance' => true,
            ]),
            // Pas encore déployé : une page HTML, pas RIVO.
            'https://b.rivo.test/branding/identity' => Http::response('<html>Bientôt disponible</html>', 200, ['Content-Type' => 'text/html']),
            'https://app.rivo.test/branding/identity' => Http::response([
                'brand' => 'RIVO', 'logo_url' => 'https://app.rivo.test/branding/logo?v=3', 'custom_logo' => true,
                'icon_url' => null, 'maintenance' => false,
            ]),
        ]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SiteSelect')
            ->missing('branding')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('branding.central.brand', 'RIVO')
                ->where('branding.central.logo_url', 'https://app.rivo.test/branding/logo?v=3')
                ->where('branding.clinics.A.status', 'online')
                ->where('branding.clinics.A.logo_url', 'https://a.rivo.test/branding/logo?v=12')
                // Une image servie par un autre domaine n'est jamais affichée.
                ->where('branding.clinics.A.icon_url', null)
                ->where('branding.clinics.M.status', 'maintenance')
                ->where('branding.clinics.B.status', 'unavailable')
                ->where('branding.clinics.B.logo_url', null)));

        Http::assertSentCount(4);

        // Gardé un moment : une seconde visite ne rappelle aucun site.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('branding.clinics.A.status', 'online')));
        Http::assertSentCount(4);
    }

    public function test_an_unreachable_portal_leaves_the_central_logo_to_the_gateway_itself(): void
    {
        config([
            'rivo.site.type' => 'gateway',
            'rivo.admin_url' => 'https://app.rivo.test',
            'rivo.clinics' => [['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.rivo.test']],
        ]);
        Http::fake(['*' => Http::response(null, 503)]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('branding.central', null)
            ->where('branding.clinics.A.status', 'unavailable')));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://a.rivo.test/branding/identity');
    }
}
