<?php

namespace Tests\Feature\Assistant;

use App\Ai\Tools\GetModuleAccess;
use App\Ai\Tools\ListAccessibleModules;
use App\Ai\Tools\SearchApplicationHelp;
use App\Models\Permission;
use App\Models\User;
use App\Services\Assistant\AssistantKnowledge;
use App\Services\Assistant\AssistantPageContext;
use App\Services\Assistant\PromptRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * ADR-222 — ce que l'assistant sait et ce qu'il laisse partir : l'aide des seuls
 * modules que le compte peut ouvrir, des outils en lecture seule, et une question
 * nettoyée de tout identifiant de patient.
 */
class AssistantKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'A', 'rivo.site.name' => 'Ambondromamy']);
    }

    public function test_every_module_has_its_help_file_with_sections(): void
    {
        $knowledge = app(AssistantKnowledge::class);

        foreach ($knowledge->moduleKeys() as $module) {
            $document = $knowledge->document($module);
            $this->assertNotSame('', $document, "resources/ai/clinic-assistant/{$module}.md manque");
            $this->assertStringContainsString("\n## ", $document, "{$module}.md n’a aucune section");
        }
    }

    public function test_help_and_search_are_limited_to_the_modules_the_account_opens(): void
    {
        $knowledge = app(AssistantKnowledge::class);
        $pharmacist = $this->userWith(['pharmacy.view', 'stock.view']);

        $this->assertTrue($knowledge->accessible('pharmacy', $pharmacist));
        $this->assertFalse($knowledge->accessible('surgery', $pharmacist));
        $this->assertFalse($knowledge->accessible('superadmin', $pharmacist), 'le portail n’existe que sur admin.rivo.mg');

        $modules = collect($knowledge->search('programmer intervention bloc chirurgien', $pharmacist))->pluck('module');
        $this->assertNotContains('surgery', $modules);

        $help = $knowledge->relevantHelp('surgery', 'programmer une intervention', $pharmacist);
        $this->assertStringNotContainsString('Programmer l\'intervention', $help);
    }

    public function test_the_page_module_is_the_longest_matching_path(): void
    {
        $knowledge = app(AssistantKnowledge::class);

        $this->assertSame('settlement', $knowledge->moduleForPath('/reception/sorties'));
        $this->assertSame('reception', $knowledge->moduleForPath('/reception/patients'));
        $this->assertSame('paraclinical', $knowledge->moduleForPath('/medicine/demandes-examens'));
        $this->assertSame('medicine', $knowledge->moduleForPath('/medicine/orientations/{id}/examen'));
        $this->assertNull($knowledge->moduleForPath('/inconnu'));
    }

    public function test_the_redactor_masks_identifiers_but_keeps_the_question(): void
    {
        $result = app(PromptRedactor::class)->redact(
            'Où trouver la facture du passage A-26-0001-02 ? Mail rakoto@example.mg, tél +261 34 12 345 67 ou 032 11 222 33, CIN 101 201 301 401, matricule EMP-0007.',
        );

        foreach (['A-26-0001', 'rakoto@example.mg', '34 12 345 67', '032 11 222 33', '101 201 301 401', 'EMP-0007'] as $secret) {
            $this->assertStringNotContainsString($secret, $result['text']);
        }
        $this->assertStringContainsString('Où trouver la facture du passage', $result['text']);
        $this->assertGreaterThanOrEqual(6, $result['redactions']);

        $plain = app(PromptRedactor::class)->redact('Comment clôturer la caisse en 2026 ?');
        $this->assertSame(0, $plain['redactions']);
        $this->assertSame('Comment clôturer la caisse en 2026 ?', $plain['text']);
    }

    public function test_the_page_context_carries_no_identifier_and_names_the_rights(): void
    {
        $context = app(AssistantPageContext::class);
        $user = $this->userWith(['pharmacy.view', 'stock.view', 'pharmacy.dispense']);

        $page = $context->normalize(['path' => '/pharmacy/dispenses/0198f1d2-aaaa-4bbb-8ccc-123456789012?q=RAKOTO#details', 'component' => 'Pharmacy/Dispenses/Show', 'section' => 'details']);
        $this->assertSame('/pharmacy/dispenses/{id}', $page['path']);
        $this->assertSame('pharmacy', $page['module']);

        $described = $context->describe($user, $page);
        $this->assertStringContainsString('pharmacy.dispense', $described);
        $this->assertStringNotContainsString('RAKOTO', $described);
        $this->assertStringNotContainsString($user->name, $described, 'le nom du compte ne part pas');
    }

    public function test_the_tools_only_read_and_follow_the_rights(): void
    {
        $knowledge = app(AssistantKnowledge::class);
        $user = $this->userWith(['pharmacy.view', 'stock.view']);

        $search = (string) (new SearchApplicationHelp($user, $knowledge))->handle(new Request(['query' => 'rupture de stock seuil']));
        $this->assertStringContainsString('Pharmacie', $search);

        $list = (string) (new ListAccessibleModules($user, $knowledge))->handle(new Request);
        $this->assertStringContainsString('Pharmacie', $list);
        $this->assertStringNotContainsString('Chirurgie', $list);

        Permission::query()->firstOrCreate(['name' => 'pharmacy.dispense'], ['label' => 'Délivrer']);
        $access = (string) (new GetModuleAccess($user, $knowledge, app(AssistantPageContext::class)))->handle(new Request(['module' => 'pharmacy']));
        $this->assertStringContainsString('stock.view', $access);
        $this->assertStringContainsString('pharmacy.dispense', $access);

        $denied = (string) (new GetModuleAccess($user, $knowledge, app(AssistantPageContext::class)))->handle(new Request(['module' => 'surgery']));
        $this->assertStringContainsString('ne peut pas ouvrir', $denied);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->withRole('PHARMACY')->create();
        $user->permissions()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $name) => [
            Permission::query()->firstOrCreate(['name' => $name], ['label' => $name])->id => ['effect' => 'allow'],
        ])->all());
        Cache::forget(Permission::CACHE_KEY);

        return $user->fresh();
    }
}
