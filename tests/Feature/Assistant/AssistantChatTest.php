<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\ClinicAssistant;
use App\Models\AssistantSetting;
use App\Models\AssistantUsage;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assistant\AssistantConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-222 — l'assistant d'aide au logiciel : une question, une réponse en flux, une
 * conversation par compte, rien de la page ni du patient envoyé au fournisseur, et
 * aucun appel réel à une API d'IA (faux du SDK Laravel AI).
 */
class AssistantChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site.name' => 'Ambondromamy']);
        // Aucun appel réseau sortant : le faux du SDK répond. Seule la sonde du rendu serveur
        // d'Inertia (serveur Vite local, absent en test) reste permise.
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:5173/*']);
        Cache::forget(AssistantConfiguration::STATUS_CACHE_KEY);
    }

    public function test_the_assistant_answers_in_a_stream_and_remembers_the_conversation(): void
    {
        $this->configure();
        ClinicAssistant::fake(['Ouvrez **Pharmacie › Médicaments & stock**.']);
        $user = $this->userWith(['ai_assistant.use', 'pharmacy.view', 'stock.view']);

        $events = $this->events($this->ask($user, 'Comment consulter le stock ?', ['path' => '/pharmacy/stock', 'component' => 'Pharmacy/Stock/Index']));

        $text = collect($events)->where('type', 'delta')->pluck('text')->implode('');
        $this->assertSame('Ouvrez **Pharmacie › Médicaments & stock**.', $text);

        $done = collect($events)->firstWhere('type', 'done');
        $this->assertNotNull($done['conversation_id']);
        $this->assertTrue(Conversation::query()->whereKey($done['conversation_id'])->where('participant_id', $user->id)->exists());

        $usage = AssistantUsage::sole();
        $this->assertSame(AssistantUsage::STATUS_COMPLETED, $usage->status);
        $this->assertSame($user->id, $usage->user_id);
        $this->assertSame('openai', $usage->provider);

        ClinicAssistant::assertPrompted(function (AgentPrompt $prompt) {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'Tu n’es pas un professionnel de santé')
                && str_contains($instructions, 'Page ouverte : /pharmacy/stock')
                && str_contains($instructions, 'Module de la page : Pharmacie')
                && str_contains($instructions, 'Consulter le stock');
        });
    }

    public function test_patient_identifiers_are_masked_before_leaving_and_in_the_stored_conversation(): void
    {
        $this->configure();
        $received = null;
        ClinicAssistant::fake(function (string $prompt) use (&$received) {
            $received = $prompt;

            return 'Ne saisissez pas ces informations.';
        });
        $user = $this->userWith(['ai_assistant.use']);

        $events = $this->events($this->ask($user, 'Le patient A-26-0001-02 (rakoto@example.mg, 034 12 345 67) ne trouve pas sa facture'));

        $this->assertStringNotContainsString('A-26-0001', $received);
        $this->assertStringNotContainsString('rakoto@example.mg', $received);
        $this->assertStringNotContainsString('034 12 345 67', $received);
        $this->assertStringContainsString('[numéro de dossier]', $received);
        $this->assertTrue(collect($events)->firstWhere('type', 'meta')['redacted']);

        $stored = Conversation::query()->sole()->messages()->where('role', 'user')->value('content');
        $this->assertStringNotContainsString('rakoto@example.mg', $stored);
    }

    public function test_the_page_url_never_carries_identifiers_to_the_provider(): void
    {
        $this->configure();
        ClinicAssistant::fake(['OK']);
        $user = $this->userWith(['ai_assistant.use', 'patients.view']);

        $this->events($this->ask($user, 'Que puis-je faire ici ?', [
            'path' => '/patients/9f1c2b3a-1111-4222-8333-444455556666?tab=passages',
            'component' => '<script>',
            'section' => 'passages',
        ]));

        ClinicAssistant::assertPrompted(function (AgentPrompt $prompt) {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'Page ouverte : /patients/{id}')
                && ! str_contains($instructions, '9f1c2b3a')
                && ! str_contains($instructions, 'tab=passages')
                && ! str_contains($instructions, '<script>');
        });
    }

    public function test_a_conversation_belongs_to_its_account_only(): void
    {
        $this->configure();
        ClinicAssistant::fake(['Première réponse.', 'Deuxième réponse.']);
        $owner = $this->userWith(['ai_assistant.use']);
        $other = $this->userWith(['ai_assistant.use']);

        $id = collect($this->events($this->ask($owner, 'Première question')))->firstWhere('type', 'done')['conversation_id'];

        // Un autre compte ne la lit, ne la continue ni ne la supprime.
        $this->actingAs($other)->getJson("/assistant/conversations/{$id}")->assertNotFound();
        $this->actingAs($other)->deleteJson("/assistant/conversations/{$id}")->assertNotFound();
        $this->actingAs($other)->postJson('/assistant/messages', ['message' => 'Suite', 'conversation_id' => $id])->assertNotFound();
        $this->actingAs($other)->getJson('/assistant/conversations')->assertJsonCount(0, 'conversations');

        // Son propriétaire la continue, la relit, puis la supprime.
        $this->events($this->ask($owner, 'Deuxième question', [], $id));
        $this->actingAs($owner)->getJson("/assistant/conversations/{$id}")
            ->assertOk()
            ->assertJsonCount(4, 'messages')
            ->assertJsonPath('messages.3.content', 'Deuxième réponse.');
        $this->actingAs($owner)->getJson('/assistant/conversations')->assertJsonCount(1, 'conversations');
        $this->actingAs($owner)->deleteJson("/assistant/conversations/{$id}")->assertOk();
        $this->assertFalse(Conversation::query()->whereKey($id)->exists());
    }

    public function test_the_assistant_needs_its_right_and_a_configuration(): void
    {
        ClinicAssistant::fake(['Jamais appelé.']);
        $without = $this->userWith([]);
        $with = $this->userWith(['ai_assistant.use']);

        $this->actingAs($without)->postJson('/assistant/messages', ['message' => 'Bonjour'])->assertForbidden();

        // Rien de configuré : l'écran le dit, sans appeler personne.
        $this->actingAs($with)->postJson('/assistant/messages', ['message' => 'Bonjour'])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'unavailable');

        $this->configure(['enabled' => false]);
        $this->actingAs($with)->postJson('/assistant/messages', ['message' => 'Bonjour'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'L’assistant est désactivé pour cet établissement.');

        ClinicAssistant::assertNeverPrompted();
    }

    public function test_the_shared_prop_offers_the_button_only_when_usable(): void
    {
        $user = $this->userWith(['ai_assistant.use']);
        $this->actingAs($user)->get('/profil')->assertInertia(fn ($page) => $page->where('assistant.available', false));

        $this->configure();
        $this->actingAs($user)->get('/profil')->assertInertia(fn ($page) => $page
            ->where('assistant.available', true)
            ->missing('assistant.provider')
            ->missing('assistant.key'));

        $this->actingAs($this->userWith([]))->get('/profil')->assertInertia(fn ($page) => $page->where('assistant.available', false));
    }

    public function test_the_question_length_is_bounded(): void
    {
        $this->configure();
        ClinicAssistant::fake(['Jamais appelé.']);

        $this->actingAs($this->userWith(['ai_assistant.use']))
            ->postJson('/assistant/messages', ['message' => str_repeat('a', AssistantConfiguration::MAX_QUESTION_LENGTH + 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        ClinicAssistant::assertNeverPrompted();
    }

    public function test_daily_limit_and_monthly_budget_stop_questions_before_any_call(): void
    {
        $this->configure(['daily_limit_per_user' => 1]);
        ClinicAssistant::fake(['Une réponse.']);
        $user = $this->userWith(['ai_assistant.use']);

        $this->events($this->ask($user, 'Une question'));
        $this->actingAs($user)->postJson('/assistant/messages', ['message' => 'Encore une'])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'daily_limit');

        AssistantSetting::query()->update(['daily_limit_per_user' => null, 'monthly_token_budget' => 1000]);
        app(AssistantConfiguration::class)->forget();
        AssistantUsage::query()->update(['input_tokens' => 800, 'output_tokens' => 300]);

        $this->actingAs($user)->postJson('/assistant/messages', ['message' => 'Et celle-ci'])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'monthly_budget');

        ClinicAssistant::assertPromptedTimes(1);
    }

    public function test_the_hourly_rate_limit_answers_in_json(): void
    {
        $this->configure(['rate_limit_per_hour' => 1]);
        ClinicAssistant::fake(['Une.', 'Deux.']);
        $user = $this->userWith(['ai_assistant.use']);

        $this->events($this->ask($user, 'Une'));
        $this->actingAs($user)->postJson('/assistant/messages', ['message' => 'Deux'])
            ->assertStatus(429)
            ->assertJsonPath('reason', 'rate_limited');
    }

    public function test_a_provider_failure_becomes_a_plain_message_without_details(): void
    {
        $this->configure();
        ClinicAssistant::fake(fn () => throw RateLimitedException::forProvider('rivo-assistant', 429));
        $user = $this->userWith(['ai_assistant.use']);

        $error = collect($this->events($this->ask($user, 'Bonjour')))->firstWhere('type', 'error');

        $this->assertSame('provider_rate_limited', $error['reason']);
        $this->assertStringNotContainsString('rivo-assistant', $error['message']);
        $this->assertSame(AssistantUsage::STATUS_FAILED, AssistantUsage::sole()->status);
        $this->assertSame('provider_rate_limited', AssistantUsage::sole()->error);

        ClinicAssistant::fake(fn () => throw new RuntimeException('sk-secret-should-never-leak'));
        $error = collect($this->events($this->ask($user, 'Bonjour')))->firstWhere('type', 'error');
        $this->assertSame('unknown', $error['reason']);
        $this->assertStringNotContainsString('sk-secret', json_encode($error));
    }

    public function test_suggestions_follow_the_page_and_the_rights(): void
    {
        $user = $this->userWith(['ai_assistant.use', 'pharmacy.view']);

        $this->actingAs($user)->getJson('/assistant/suggestions?path=/pharmacy/stock')
            ->assertOk()
            ->assertJsonPath('module', 'Pharmacie')
            ->assertJsonFragment(['Comment enregistrer une sortie de pharmacie ?']);

        // Un module que le compte ne peut pas ouvrir : les questions générales, jamais les siennes.
        $this->actingAs($user)->getJson('/assistant/suggestions?path=/surgery')
            ->assertOk()
            ->assertJsonPath('module', null)
            ->assertJsonMissing(['Comment programmer une intervention ?']);
    }

    /** @param array<string, mixed> $overrides */
    private function configure(array $overrides = []): void
    {
        AssistantSetting::query()->delete();
        AssistantSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'gpt-test-model',
            'api_key' => 'sk-test-0000000000001234',
            ...$overrides,
        ]);

        app(AssistantConfiguration::class)->forget();
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->withRole('RECEPTION')->create();
        $ids = collect($permissions)->mapWithKeys(fn (string $name) => [
            Permission::query()->firstOrCreate(['name' => $name], ['label' => $name])->id => ['effect' => 'allow'],
        ])->all();
        $user->permissions()->syncWithoutDetaching($ids);
        Cache::forget(Permission::CACHE_KEY);

        return $user->fresh();
    }

    /** @param array<string, mixed> $page */
    private function ask(User $user, string $message, array $page = [], ?string $conversationId = null): TestResponse
    {
        $response = $this->actingAs($user)->post('/assistant/messages', array_filter([
            'message' => $message,
            'conversation_id' => $conversationId,
            'page' => $page ?: null,
        ]), ['Accept' => 'text/event-stream']);

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function events(TestResponse $response): array
    {
        return collect(explode("\n\n", $response->streamedContent()))
            ->map(fn (string $block) => trim($block))
            ->filter(fn (string $block) => str_starts_with($block, 'data: '))
            ->map(fn (string $block) => json_decode(substr($block, 6), true))
            ->filter()
            ->values()
            ->all();
    }
}
