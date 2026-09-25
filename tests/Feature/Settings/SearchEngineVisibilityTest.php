<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-184 — une application masquée aux moteurs de recherche le dit partout :
 * robots.txt refuse toute exploration, chaque page porte la balise « noindex »,
 * et chaque réponse — images et API comprises — l'en-tête X-Robots-Tag.
 * Décochée, l'application redevient ce qu'elle était : rien de tout cela.
 */
class SearchEngineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.search_engines.hidden' => true]);
    }

    public function test_an_unconfigured_site_is_hidden_by_default_everywhere(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Robots-Tag', AppSettings::ROBOTS_DIRECTIVES)
            ->assertSee("User-agent: *\nDisallow: /", false);

        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', AppSettings::ROBOTS_DIRECTIVES)
            ->assertSee('<meta name="robots" content="'.AppSettings::ROBOTS_DIRECTIVES.'">', false);

        // Une réponse d'API n'a pas de balise où porter la consigne : l'en-tête la porte.
        $this->getJson('/api/v1/super-admin/app-settings')
            ->assertHeader('X-Robots-Tag', AppSettings::ROBOTS_DIRECTIVES);
    }

    public function test_unchecking_the_box_makes_the_site_visible_again(): void
    {
        AppSetting::query()->create(['search_engines_hidden' => false]);

        $robots = $this->get('/robots.txt')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->assertSame("User-agent: *\nDisallow:\n", $robots->getContent());

        $this->get('/login')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertDontSee('<meta name="robots"', false);
    }

    public function test_a_site_setting_overrides_the_deployment_configuration(): void
    {
        config(['rivo.search_engines.hidden' => false]);

        $this->get('/robots.txt')->assertHeaderMissing('X-Robots-Tag')->assertDontSee('Disallow: /', false);

        AppSetting::query()->create(['search_engines_hidden' => true]);
        app(AppSettings::class)->forget();

        $this->get('/robots.txt')->assertSee('Disallow: /', false)->assertHeader('X-Robots-Tag', AppSettings::ROBOTS_DIRECTIVES);
    }

    public function test_the_tagline_reaches_every_page_and_follows_the_site(): void
    {
        config(['rivo.tagline' => 'Ny fahasalamana no loharanon-karena']);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('site.tagline', 'Ny fahasalamana no loharanon-karena'));

        AppSetting::query()->create(['app_tagline' => 'Votre santé, notre priorité']);
        app(AppSettings::class)->forget();

        $this->get('/login')->assertInertia(fn ($page) => $page->where('site.tagline', 'Votre santé, notre priorité'));

        // Sans devise nulle part, la ligne disparaît : rien n'est inventé.
        AppSetting::query()->update(['app_tagline' => null]);
        config(['rivo.tagline' => '']);
        app(AppSettings::class)->forget();

        $this->get('/login')->assertInertia(fn ($page) => $page->where('site.tagline', null));
    }

    public function test_no_static_robots_file_can_shadow_the_site_setting(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'));
    }
}
