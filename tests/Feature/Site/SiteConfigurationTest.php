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
        ]);

        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page
            ->where('site.code', 'M')
            ->where('site.name', 'Mampikony')
            ->where('site.type', 'clinic')
            ->where('site.brand', 'Clinique Saint Georges')
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
        // config/rivo.php defaults an unset RIVO_SITE_TYPE to 'clinic' so a
        // plain checkout without site env vars never accidentally behaves
        // like the public picker or the admin portal.
        $this->assertSame('clinic', config('rivo.site.type'));
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
