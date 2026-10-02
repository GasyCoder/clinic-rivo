<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Une réponse Inertia (JSON) n'est jamais gardée par le navigateur.
 *
 * Le proxy de l'hébergeur remplace `Vary: X-Inertia` : sans `no-store`, le
 * navigateur rangeait le JSON sous l'adresse de la page, et un retour arrière
 * l'affichait brut à la place de l'application.
 */
class InertiaCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inertia_visit_is_never_stored_by_the_browser(): void
    {
        $this->get('/login', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/login')),
        ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertHeader('Vary', 'X-Inertia')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_an_inertia_version_change_is_not_stored_either(): void
    {
        $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'ancienne'])
            ->assertStatus(409)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_html_page_keeps_its_usual_cache_header(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
