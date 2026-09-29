<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\AssistantConnectionCheck;
use App\Models\AssistantSetting;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assistant\AssistantConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\TestCase;

/**
 * ADR-222 — les réglages de l'assistant d'un site, écrits depuis le portail par
 * l'API du site : la clé chiffrée, jamais renvoyée ni journalisée, jamais effacée
 * par un champ laissé vide ; un test de connexion sans appel réel (faux du SDK).
 */
class AssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/super-admin/assistant-settings';

    private const KEY = 'sk-proj-SECRET-abcdefghijklmnop-9876';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
            'ai.providers.openai.key' => null,
            'ai.providers.anthropic.key' => null,
        ]);
        // Aucun appel réseau sortant, sauf la sonde du rendu serveur d'Inertia (absente en test).
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:5173/*']);
        Cache::forget(AssistantConfiguration::STATUS_CACHE_KEY);
    }

    public function test_the_key_is_encrypted_masked_and_never_returned(): void
    {
        $response = $this->withHeaders($this->headers(['ai_settings.update']))
            ->putJson(self::URL, $this->values(['api_key' => self::KEY]))
            ->assertOk()
            ->assertJsonPath('data.key.present', true)
            ->assertJsonPath('data.key.source', 'database')
            ->assertJsonPath('data.effective.available', true);

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringEndsWith('9876', $response->json('data.key.masked'));

        // En base : chiffrée, jamais en clair ; le modèle ne la sérialise pas.
        $raw = DB::table('ai_assistant_settings')->value('api_key');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertStringNotContainsString('SECRET', $raw);
        $this->assertSame(self::KEY, AssistantSetting::sole()->api_key);
        $this->assertArrayNotHasKey('api_key', AssistantSetting::sole()->toArray());

        // L'audit dit « remplacée », jamais la clé ; la réponse idempotente gardée non plus.
        $audit = AuditLog::query()->where('action', 'ai_settings.update')->sole();
        $this->assertSame('remplacée', $audit->new_values['api_key']);
        $this->assertStringNotContainsString(self::KEY, json_encode($audit->toArray()));
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('api_idempotency_records')->pluck('response_body')->implode(''));
    }

    public function test_saving_without_a_key_keeps_the_stored_one(): void
    {
        $this->withHeaders($this->headers(['ai_settings.update']))->putJson(self::URL, $this->values(['api_key' => self::KEY]))->assertOk();

        $this->withHeaders($this->headers(['ai_settings.update']))
            ->putJson(self::URL, $this->values(['model' => 'gpt-autre-modele', 'api_key' => '']))
            ->assertOk()
            ->assertJsonPath('data.values.model', 'gpt-autre-modele')
            ->assertJsonPath('data.key.present', true);

        $this->assertSame(self::KEY, AssistantSetting::sole()->api_key);
    }

    public function test_changing_provider_needs_the_new_key(): void
    {
        $this->withHeaders($this->headers(['ai_settings.update']))->putJson(self::URL, $this->values(['api_key' => self::KEY]))->assertOk();

        $this->withHeaders($this->headers(['ai_settings.update']))
            ->putJson(self::URL, $this->values(['provider' => 'anthropic', 'model' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('api_key');

        $this->assertSame('openai', AssistantSetting::sole()->provider->value);
    }

    public function test_removing_the_key_is_an_explicit_audited_gesture(): void
    {
        $this->withHeaders($this->headers(['ai_settings.update']))->putJson(self::URL, $this->values(['api_key' => self::KEY]))->assertOk();

        $this->withHeaders($this->headers(['ai_settings.view']))->deleteJson(self::URL.'/key')->assertForbidden();
        $this->withHeaders($this->headers(['ai_settings.update']))
            ->deleteJson(self::URL.'/key')
            ->assertOk()
            ->assertJsonPath('data.key.present', false)
            ->assertJsonPath('data.effective.available', false);

        $this->assertNull(AssistantSetting::sole()->api_key);
        $this->assertTrue(AuditLog::query()->where('action', 'ai_settings.key_remove')->exists());
    }

    public function test_the_environment_key_is_the_fallback(): void
    {
        config(['ai.providers.openai.key' => 'sk-from-env-key-4321']);
        $this->withHeaders($this->headers(['ai_settings.update']))->putJson(self::URL, $this->values())->assertOk()
            ->assertJsonPath('data.key.source', 'environment')
            ->assertJsonPath('data.effective.configured', true);
    }

    public function test_rights_are_rechecked_by_the_site(): void
    {
        $this->withHeaders($this->headers(['settings.update']))->putJson(self::URL, $this->values())->assertForbidden();
        $this->withHeaders($this->headers([]))->getJson(self::URL)->assertForbidden();
        $this->withHeaders($this->headers(['ai_settings.view']))->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.installed', true)
            ->assertJsonStructure(['data' => ['providers' => [['value', 'label', 'models', 'key_url']], 'usage', 'limits']]);
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->withHeaders($this->headers(['ai_settings.update']))
            ->putJson(self::URL, $this->values(['provider' => 'inconnu', 'model' => 'bad model;drop', 'temperature' => 5, 'api_key' => 'a b c d e f g h']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider', 'model', 'temperature', 'api_key']);
    }

    public function test_connection_test_uses_the_form_values_without_saving_or_leaking_the_key(): void
    {
        AssistantConnectionCheck::fake(['OK']);

        $response = $this->withHeaders($this->headers(['ai_settings.update']))
            ->postJson(self::URL.'/test', ['provider' => 'openai', 'model' => 'gpt-test', 'api_key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.key_source', 'typed');

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertSame(0, AssistantSetting::query()->count(), 'un test n’enregistre rien');
        $audit = AuditLog::query()->where('action', 'ai_settings.test')->sole();
        $this->assertStringNotContainsString(self::KEY, json_encode($audit->toArray()));

        AssistantConnectionCheck::fake(fn () => throw RateLimitedException::forProvider('rivo-assistant-test', 429));
        $this->withHeaders($this->headers(['ai_settings.update']))
            ->postJson(self::URL.'/test', ['provider' => 'openai', 'api_key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.reason', 'provider_rate_limited');

        // Sans clé nulle part : le test le dit sans rien appeler.
        $this->withHeaders($this->headers(['ai_settings.update']))
            ->postJson(self::URL.'/test', ['provider' => 'anthropic'])
            ->assertOk()
            ->assertJsonPath('data.reason', 'no_key');
    }

    public function test_the_portal_configures_its_own_assistant_and_reads_the_sites_through_their_api(): void
    {
        config([
            'rivo.site.type' => 'admin',
            'rivo.clinics' => [['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'token-a']],
        ]);
        Http::fake(['https://a.test/*' => Http::response(['data' => ['installed' => true, 'values' => [], 'key' => ['present' => true, 'masked' => '••••1234']]])]);
        $admin = $this->portalAdmin();

        $this->actingAs($admin)->get('/super-admin/settings/assistant?site=A')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assistant.A.data.key.masked', '••••1234')
                ->where('assistant.PORTAL.data.installed', true));

        $this->actingAs($admin)
            ->put('/super-admin/settings/assistant', $this->values(['site_code' => 'PORTAL', 'api_key' => self::KEY]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(self::KEY, AssistantSetting::sole()->api_key);

        // La clé d'un site part à son API, de serveur à serveur, et n'est jamais relue.
        Http::fake(['https://a.test/*' => Http::response(['message' => 'ok', 'data' => []])]);
        $this->actingAs($admin)->put('/super-admin/settings/assistant', $this->values(['site_code' => 'A', 'api_key' => self::KEY]))->assertRedirect();
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/super-admin/assistant-settings')
            && $request['api_key'] === self::KEY);
    }

    public function test_the_portal_super_admin_sees_the_button_before_any_setting_and_is_led_to_them(): void
    {
        config(['rivo.site.type' => 'admin', 'rivo.clinics' => []]);
        $admin = $this->portalAdmin(['ai_assistant.use']);

        // Rien de réglé : le bouton est proposé au seul Super Administrateur, avec le chemin des réglages.
        $this->actingAs($admin)->get('/profil')->assertInertia(fn ($page) => $page
            ->where('assistant.available', false)
            ->where('assistant.setup.state', 'unconfigured')
            ->where('assistant.setup.url', '/super-admin/settings/assistant?site=PORTAL')
            ->missing('assistant.provider')
            ->missing('assistant.key'));

        // Configuré mais éteint : il le dit.
        AssistantSetting::query()->create(['enabled' => false, 'provider' => 'openai', 'model' => 'gpt-test-model', 'api_key' => self::KEY]);
        app(AssistantConfiguration::class)->forget();
        $this->actingAs($admin)->get('/profil')->assertInertia(fn ($page) => $page->where('assistant.setup.state', 'disabled'));

        // Prêt : plus d'invitation, l'assistant lui-même.
        AssistantSetting::query()->update(['enabled' => true]);
        app(AssistantConfiguration::class)->forget();
        $this->actingAs($admin)->get('/profil')->assertInertia(fn ($page) => $page
            ->where('assistant.available', true)
            ->where('assistant.setup', null));

        // Sur un site, personne n'y règle l'assistant : un compte qui ne peut pas s'en servir ne voit rien.
        config(['rivo.site.type' => 'clinic']);
        AssistantSetting::query()->delete();
        app(AssistantConfiguration::class)->forget();
        $clinician = User::factory()->withRole('RECEPTION')->create();
        $this->actingAs($clinician)->get('/profil')->assertInertia(fn ($page) => $page
            ->where('assistant.available', false)
            ->where('assistant.setup', null));
    }

    public function test_a_refused_form_never_keeps_the_key_in_the_session(): void
    {
        config(['rivo.site.type' => 'admin', 'rivo.clinics' => []]);
        $admin = $this->portalAdmin();

        $this->actingAs($admin)
            ->from('/super-admin/settings/assistant')
            ->put('/super-admin/settings/assistant', $this->values(['site_code' => 'PORTAL', 'api_key' => self::KEY, 'temperature' => 9]))
            ->assertRedirect()
            ->assertSessionHasErrors('temperature');

        // Les autres champs reviennent dans le formulaire ; la clé, jamais (la session vit en base).
        $this->assertSame('Tutoyez les utilisateurs.', session()->getOldInput('instructions'));
        $this->assertNull(session()->getOldInput('api_key'));
        $this->assertStringNotContainsString(self::KEY, serialize(session()->all()));
        $this->assertNull(AssistantSetting::query()->first());
    }

    /** @param array<string, mixed> $overrides */
    private function values(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'gpt-test-model',
            'max_output_tokens' => 600,
            'temperature' => null,
            'timeout_seconds' => 30,
            'rate_limit_per_hour' => 20,
            'daily_limit_per_user' => null,
            'monthly_token_budget' => null,
            'instructions' => 'Tutoyez les utilisateurs.',
            ...$overrides,
        ];
    }

    /** @param array<int, string> $extra */
    private function portalAdmin(array $extra = []): User
    {
        $admin = User::factory()->withRole('SUPER_ADMIN')->create();
        $ids = collect(['super_admin.portal.view', 'settings.view', 'settings.update', 'ai_settings.view', 'ai_settings.update', ...$extra])
            ->mapWithKeys(fn (string $name) => [Permission::query()->firstOrCreate(['name' => $name], ['label' => $name])->id => ['effect' => 'allow']])
            ->all();
        $admin->permissions()->syncWithoutDetaching($ids);
        Cache::forget(Permission::CACHE_KEY);

        return $admin->fresh();
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }
}
