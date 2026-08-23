<?php

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mampikony_site_identity_is_shared_with_the_frontend(): void
    {
        config([
            'rivo.site.code' => 'M',
            'rivo.site.name' => 'Mampikony',
            'rivo.site.type' => 'clinic',
            'rivo.documents' => [
                'logo_url' => '/images/clinic-logo.svg',
                'nif' => 'NIF-TEST',
                'stat' => 'STAT-TEST',
                'address' => 'Mampikony',
                'phone' => '+261 00 000 00',
                'email' => 'contact@example.test',
            ],
        ]);

        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page
            ->where('site.code', 'M')
            ->where('site.name', 'Mampikony')
            ->where('site.type', 'clinic')
            ->where('site.brand', 'Clinique Saint Georges')
            ->where('site.gatewayUrl', 'https://app.rivo.mg')
            ->where('site.publicUrl', 'https://cliniquesaintgeorges.mg')
            ->where('site.documents.logo_url', '/images/clinic-logo.svg')
            ->where('site.documents.nif', 'NIF-TEST')
            ->where('site.documents.stat', 'STAT-TEST')
            ->where('site.documents.address', 'Mampikony')
            ->where('site.documents.phone', '+261 00 000 00')
            ->where('site.documents.email', 'contact@example.test')
        );
    }

    public function test_ambondromamy_site_identity_is_shared_with_the_frontend(): void
    {
        config([
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site.type' => 'clinic',
        ]);

        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page
            ->where('site.code', 'A')
            ->where('site.name', 'Ambondromamy')
            ->where('site.type', 'clinic')
        );
    }

    public function test_admin_site_identity_is_shared_with_the_frontend(): void
    {
        config([
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.site.type' => 'admin',
        ]);

        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page
            ->where('site.code', 'ADMIN')
            ->where('site.name', 'Super Administration')
            ->where('site.type', 'admin')
            // No "RIVO" brand on the admin portal — same brand as every
            // other deployment, only the site name line differs.
            ->where('site.brand', 'Clinique Saint Georges')
        );
    }

    public function test_an_unconfigured_site_type_falls_back_to_the_clinic_default(): void
    {
        // Exercises env('RIVO_SITE_TYPE', 'clinic')'s actual fallback, not
        // just phpunit.xml's pinned baseline (which is 'clinic' anyway and
        // would make this tautological) — temporarily removes the variable
        // from the environment, re-evaluates config/rivo.php fresh, and
        // restores it, so a plain checkout without site env vars is
        // verified to never accidentally behave like the staff gateway or
        // the admin portal.
        $previous = [
            'env' => getenv('RIVO_SITE_TYPE'),
            'server' => $_SERVER['RIVO_SITE_TYPE'] ?? null,
        ];

        putenv('RIVO_SITE_TYPE');
        unset($_ENV['RIVO_SITE_TYPE'], $_SERVER['RIVO_SITE_TYPE']);

        try {
            $config = require config_path('rivo.php');

            $this->assertSame('clinic', $config['site']['type']);
        } finally {
            if ($previous['env'] !== false) {
                putenv("RIVO_SITE_TYPE={$previous['env']}");
                $_ENV['RIVO_SITE_TYPE'] = $previous['env'];
            }

            if ($previous['server'] !== null) {
                $_SERVER['RIVO_SITE_TYPE'] = $previous['server'];
            }
        }
    }

    public function test_guest_pages_still_share_a_site_key_even_without_a_configured_name(): void
    {
        config([
            'rivo.site.code' => null,
            'rivo.site.name' => null,
        ]);

        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page
            ->where('site.name', null)
            ->where('site.brand', 'Clinique Saint Georges')
        );
    }
}
