<?php

namespace Tests\Feature\SuperAdmin;

use App\Ai\Agents\SupplierProductMatcher;
use App\Models\AssistantSetting;
use App\Models\AssistantUsage;
use App\Models\Role;
use App\Models\User;
use App\Services\Assistant\AssistantConfiguration;
use App\Services\Pharmacy\SupplierProductAiMatcher;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-241 — depuis le portail : décisions, dictionnaire, IA et prompt d'un
 * catalogue passent par l'API du site. L'IA ne reçoit que des libellés.
 */
class SupplierEquivalencePortalTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://a.test/api/v1/super-admin/pharmacy';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
        Cache::forget(AssistantConfiguration::STATUS_CACHE_KEY);
    }

    public function test_a_decision_is_forwarded_to_the_site(): void
    {
        Http::fake([self::API.'/product-equivalences' => Http::response(['message' => 'Mémorisé : c’est le même produit.', 'data' => ['written' => 1]])]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/pharmacy-suppliers/A/equivalences', [
                'item_uuid' => '7f0d8a6e-1c2b-4d3e-8f9a-0b1c2d3e4f50',
                'status' => 'SAME',
                'other_item_uuids' => ['7f0d8a6e-1c2b-4d3e-8f9a-0b1c2d3e4f51'],
            ])
            ->assertSessionHas('status', 'Mémorisé : c’est le même produit.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === self::API.'/product-equivalences'
            && $request['status'] === 'SAME'
            && $request->hasHeader('X-Rivo-Actor-UUID', $this->superAdmin->uuid));
    }

    public function test_the_catalog_prompt_comes_from_the_site(): void
    {
        Http::fake([self::API.'/suppliers/sup-1/catalogs/cat-1/structure' => Http::response(['data' => ['prompt' => 'Le prompt.', 'structure' => ['readable' => true]]])]);

        $this->actingAs($this->superAdmin)
            ->getJson('/super-admin/pharmacy-suppliers/A/sup-1/catalogs/cat-1/prompt')
            ->assertOk()
            ->assertJsonPath('data.prompt', 'Le prompt.');
    }

    public function test_ai_matching_sends_only_unmatched_labels_and_stores_proposals_on_the_site(): void
    {
        $this->configureAi();
        $received = null;
        SupplierProductMatcher::fake(function (string $prompt) use (&$received) {
            $received = $prompt;

            return '{"pairs":[{"a":1,"b":2,"why":"Doliprane est du paracétamol 500 mg."},{"a":1,"b":3},{"a":9,"b":1}]}';
        });

        Http::fake([
            self::API.'/supplier-offers*' => Http::response(['data' => ['suppliers' => [], 'medicines' => [
                $this->row('Doliprane 500', 'item-a', 'Fournisseur A'),
                $this->row('Paracetamol 500mg', 'item-b', 'Fournisseur B'),
                // Même fournisseur que la première : la paire est écartée.
                $this->row('Dafalgan 500', 'item-c', 'Fournisseur A'),
                // Déjà proposée par la règle : elle ne part pas à l'IA.
                [...$this->row('Seringue 5ml', 'item-d', 'Fournisseur B'), 'peers' => [['row_key' => 'x']]],
                // Au catalogue de la clinique, avec son prix : jamais envoyé.
                [...$this->row('Amoxicilline 1g', 'item-e', 'Fournisseur B'), 'in_clinic_catalog' => true],
            ]]]),
            self::API.'/product-equivalences/proposals' => Http::response(['message' => '1 rapprochement(s) proposé(s), à confirmer.', 'data' => ['proposed' => 1]]),
        ]);

        $plan = $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/pharmacy-suppliers/A/commander/ia', [])
            ->assertOk()
            ->assertJsonCount(1, 'data.batches')
            ->json();

        // Rien n'est encore parti à l'IA : seul le plan est prêt.
        $this->assertNull($received);

        $this->actingAs($this->superAdmin)
            ->postJson("/super-admin/pharmacy-suppliers/A/commander/ia/{$plan['data']['run']}/0")
            ->assertOk()
            ->assertJsonPath('data.proposed', 1)
            ->assertJsonPath('message', '1 rapprochement(s) proposé(s), à confirmer.');

        // Un autre compte ne relance pas le lot d'un autre.
        $other = User::factory()->create(['role_id' => $this->superAdmin->role_id]);
        $this->actingAs($other)
            ->postJson("/super-admin/pharmacy-suppliers/A/commander/ia/{$plan['data']['run']}/0")
            ->assertNotFound();

        $this->assertStringContainsString('1. Doliprane 500 — Boîte [Fournisseur A]', $received);
        $this->assertStringNotContainsString('Seringue', $received);
        $this->assertStringNotContainsString('Amoxicilline', $received);
        // Aucun prix ne part au fournisseur d'IA.
        $this->assertStringNotContainsString('1200', $received);

        Http::assertSent(fn ($request) => $request->url() === self::API.'/product-equivalences/proposals'
            && $request['pairs'] === [['item_uuid' => 'item-a', 'other_item_uuid' => 'item-b', 'reason' => 'Doliprane est du paracétamol 500 mg.']]);

        $this->assertSame(1, AssistantUsage::query()->where('status', AssistantUsage::STATUS_COMPLETED)->count());
    }

    public function test_ai_matching_says_so_when_the_assistant_is_not_configured(): void
    {
        Http::fake([self::API.'/supplier-offers*' => Http::response(['data' => ['medicines' => [
            $this->row('Doliprane 500', 'item-a', 'Fournisseur A'),
            $this->row('Paracetamol 500mg', 'item-b', 'Fournisseur B'),
        ]]])]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/pharmacy-suppliers/A/commander/ia', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'L’assistant IA n’est pas configuré sur le portail (Paramètres › Assistant IA).');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'proposals'));
    }

    public function test_the_answer_is_read_defensively(): void
    {
        $lines = [
            ['item_uuid' => 'a', 'label' => 'A', 'supplier' => 'X'],
            ['item_uuid' => 'b', 'label' => 'B', 'supplier' => 'Y'],
        ];

        $this->assertSame([], SupplierProductAiMatcher::pairs('Désolé, je ne sais pas.', $lines));
        $this->assertSame([], SupplierProductAiMatcher::pairs('{"pairs":[{"a":1,"b":1}]}', $lines));
        $this->assertCount(1, SupplierProductAiMatcher::pairs("```json\n{\"pairs\":[{\"a\":2,\"b\":1},{\"a\":1,\"b\":2}]}\n```", $lines));
    }

    public function test_many_lines_are_split_into_batches_of_neighbours(): void
    {
        $lines = [];
        foreach (range(1, 120) as $i) {
            $lines[] = ['item_uuid' => "big-{$i}", 'label' => "Produit grand {$i} gel", 'supplier' => 'Grand'];
        }
        $lines[] = ['item_uuid' => 'small-1', 'label' => 'Amoxicilline 1g gelule', 'supplier' => 'Petit'];
        $lines[] = ['item_uuid' => 'big-amox', 'label' => 'AMOXICILLINE 1000 MG GELULES', 'supplier' => 'Grand'];
        $lines[] = ['item_uuid' => 'small-2', 'label' => 'Xylozzz inconnu', 'supplier' => 'Petit'];

        $plan = SupplierProductAiMatcher::plan($lines);

        $this->assertCount(1, $plan['batches']);
        $this->assertSame(1, $plan['alone']);
        $uuids = array_column($plan['batches'][0], 'item_uuid');
        $this->assertSame('small-1', $uuids[0]);
        $this->assertContains('big-amox', $uuids);
        $this->assertLessThanOrEqual(SupplierProductAiMatcher::BATCH_LINES, count($uuids));
    }

    /** @return array<string, mixed> */
    private function row(string $name, string $item, string $supplier): array
    {
        return [
            'key' => 'catalog:'.$item,
            'name' => $name,
            'unit' => 'Boîte',
            'family' => null,
            'in_clinic_catalog' => false,
            'members' => ['item_uuids' => [$item], 'medicine_uuid' => null],
            'quotes' => [['supplier_name' => $supplier, 'price' => '1200']],
            'suggestions' => [],
            'peers' => [],
        ];
    }

    private function configureAi(): void
    {
        AssistantSetting::query()->create(['enabled' => true, 'provider' => 'openai', 'model' => 'gpt-test-model', 'api_key' => 'sk-test-0000000000001234']);
        app(AssistantConfiguration::class)->forget();
    }
}
