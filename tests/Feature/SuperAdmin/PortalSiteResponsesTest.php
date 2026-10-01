<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * En production, Mampikony et Boriziny répondaient par une page HTML « Bientôt
 * disponible », servie en 200 à n'importe quelle adresse. Le portail les marquait « en
 * ligne » sans données, et l'écran des paramètres plantait : page blanche.
 */
class PortalSiteResponsesTest extends TestCase
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
                ['code' => 'B', 'name' => 'Boriziny', 'url' => 'https://b.test', 'api_url' => 'https://b.test/api/v1', 'api_token' => 'b-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_a_waiting_page_served_in_200_is_never_read_as_an_online_site(): void
    {
        Http::fake([
            'https://m.test/*' => Http::response('<!DOCTYPE html><title>Bientôt disponible</title>', 200, ['Content-Type' => 'text/html']),
            'https://a.test/*' => Http::response(['data' => ['brand' => 'Clinique']], 200),
            'https://b.test/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $sites = collect(app(PortalSiteApiClient::class)->appSettingsForAllSites($this->superAdmin))->keyBy('site.code');

        $this->assertSame(['OFFLINE', false], [$sites['M']['status'], $sites['M']['ok']]);
        $this->assertStringContainsString('ne répond pas comme une API RIVO', $sites['M']['message']);
        $this->assertSame(['ONLINE', 'Clinique'], [$sites['A']['status'], $sites['A']['data']['brand']]);
        // Un site injoignable ne retient pas les autres, et reste dit injoignable.
        $this->assertSame('OFFLINE', $sites['B']['status']);
    }

    public function test_the_settings_of_a_site_still_waiting_for_its_deployment_say_so(): void
    {
        Http::fake(['https://m.test/*' => Http::response('<!DOCTYPE html><title>Bientôt disponible</title>', 200, ['Content-Type' => 'text/html'])]);

        $this->actingAs($this->superAdmin)->get('/super-admin/settings/identite?site=M')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SuperAdmin/Settings/Index'));
    }

    public function test_a_relayed_screen_never_shows_the_waiting_page_as_a_site_document(): void
    {
        Http::fake(['https://m.test/*' => Http::response('<!DOCTYPE html><title>Bientôt disponible</title>', 200, ['Content-Type' => 'text/html'])]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/M/rh')
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'ne répond pas comme une API RIVO'));
    }
}
