<?php

namespace Tests\Feature\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Laboratory\AnalysisCatalogImportService;
use App\Services\Laboratory\AnalysisCatalogManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalysisCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_administration_can_manage_analysis_definitions_and_reference_values(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);

        $this->actingAs($actor)
            ->post('/administration/analyses', [
                'catalog_item_uuid' => $service->uuid,
                'parent_uuid' => null,
                'code' => 'GLYC',
                'level' => 'NORMAL',
                'designation' => 'Glycémie',
                'description' => 'Dosage du glucose',
                'result_type' => 'NUMERIC',
                'reference_general' => '0,70–1,10',
                'reference_male' => null,
                'reference_female' => null,
                'reference_child_male' => null,
                'reference_child_female' => null,
                'unit' => 'g/L',
                'predefined_values' => [],
                'display_order' => 10,
                'is_active' => true,
            ])
            ->assertRedirect();

        $analysis = AnalysisCatalog::query()->where('code', 'GLYC')->firstOrFail();
        $this->assertSame($service->id, $analysis->catalog_item_id);
        $this->assertSame('0,70–1,10', $analysis->reference_general);

        $this->actingAs($actor)
            ->get('/administration/analyses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Analyses/Index')
                ->where('analyses.data.0.code', 'GLYC')
                ->where('analyses.data.0.unit', 'g/L')
                ->has('catalogItems', 1));

        $this->actingAs($actor)
            ->post("/administration/analyses/{$analysis->uuid}/deactivate")
            ->assertRedirect();

        $this->assertFalse($analysis->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => AnalysisCatalog::class,
            'entity_id' => $analysis->id,
            'action' => 'update',
        ]);
    }

    public function test_analysis_must_reference_a_laboratory_service(): void
    {
        $actor = $this->administrationUser();
        $medicineService = CatalogItem::query()->create([
            'code' => 'CONSULT', 'name' => 'Consultation',
            'type' => CatalogItemType::Service->value, 'module' => CatalogModule::Medicine->value,
            'unit' => 'consultation', 'billable' => true, 'stockable' => false,
            'reception_selectable' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->post('/administration/analyses', [
                'catalog_item_uuid' => $medicineService->uuid,
                'code' => 'INVALID', 'level' => 'NORMAL', 'designation' => 'Invalide',
                'result_type' => 'TEXT', 'display_order' => 0, 'is_active' => true,
            ])
            ->assertSessionHasErrors('catalog_item_uuid');

        $this->assertDatabaseCount('analysis_catalogs', 0);
    }

    public function test_import_template_and_export_are_available_as_excel(): void
    {
        $actor = $this->administrationUser();
        $this->laboratoryService($actor);

        $this->actingAs($actor)
            ->get('/administration/analyses/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($actor)
            ->get('/administration/analyses/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_a_parent_group_can_contain_another_parent_group_and_terminal_results(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);

        $root = app(AnalysisCatalogManager::class)->create(
            $this->definition($service, 'COPRO', 'PARENT', 'Coproculture'),
            $actor,
        );
        $section = app(AnalysisCatalogManager::class)->create(
            $this->definition($service, 'COPRO-GRAM', 'PARENT', 'Aspect général de la flore', $root->uuid),
            $actor,
        );
        $result = app(AnalysisCatalogManager::class)->create(
            $this->definition($service, 'COPRO-GRAM-POS', 'CHILD', 'Bactéries à Gram positif', $section->uuid),
            $actor,
        );

        $this->assertTrue($section->fresh()->parent->is($root));
        $this->assertTrue($result->fresh()->parent->is($section));

        $cyclePayload = $this->definition($service, 'COPRO', 'PARENT', 'Coproculture', $section->uuid);
        $this->actingAs($actor)
            ->put("/administration/analyses/{$root->uuid}", $cyclePayload)
            ->assertSessionHasErrors('parent_uuid');

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_excel_import_resolves_nested_groups_even_when_rows_are_out_of_order(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);
        $row = fn (string $code, string $level, string $parent, string $designation): array => [
            'code_prestation' => $service->code,
            'code_analyse' => $code,
            'niveau' => $level,
            'code_parent' => $parent,
            'designation' => $designation,
            'type_resultat' => 'TEXT',
            'ordre' => 1,
            'statut' => 'ACTIVE',
        ];

        $result = app(AnalysisCatalogImportService::class)->import([
            $row('TREE-RESULT', 'CHILD', 'TREE-SECTION', 'Résultat'),
            $row('TREE-SECTION', 'PARENT', 'TREE-ROOT', 'Sous-groupe'),
            $row('TREE-ROOT', 'PARENT', '', 'Groupe racine'),
        ], $actor);

        $this->assertSame(['created' => 3, 'updated' => 0], $result);
        $root = AnalysisCatalog::query()->where('code', 'TREE-ROOT')->firstOrFail();
        $section = AnalysisCatalog::query()->where('code', 'TREE-SECTION')->firstOrFail();
        $leaf = AnalysisCatalog::query()->where('code', 'TREE-RESULT')->firstOrFail();
        $this->assertTrue($section->parent->is($root));
        $this->assertTrue($leaf->parent->is($section));
    }

    public function test_moving_a_group_to_a_different_service_cascades_the_catalog_item_to_its_whole_subtree(): void
    {
        $actor = $this->administrationUser();
        $serviceA = $this->laboratoryService($actor);
        $serviceB = CatalogItem::query()->create([
            'code' => 'LAB-URIC', 'name' => 'Uricémie',
            'type' => CatalogItemType::Service->value, 'module' => CatalogModule::Laboratory->value,
            'unit' => 'analyse', 'billable' => true, 'stockable' => false,
            'reception_selectable' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $manager = app(AnalysisCatalogManager::class);

        $root = $manager->create($this->definition($serviceA, 'MOVE-ROOT', 'PARENT', 'Groupe racine'), $actor);
        $section = $manager->create($this->definition($serviceA, 'MOVE-SECTION', 'PARENT', 'Sous-groupe', $root->uuid), $actor);
        $leaf = $manager->create($this->definition($serviceA, 'MOVE-LEAF', 'CHILD', 'Résultat', $section->uuid), $actor);

        $manager->update($root, $this->definition($serviceB, 'MOVE-ROOT', 'PARENT', 'Groupe racine'), $actor);

        $this->assertSame($serviceB->id, $root->fresh()->catalog_item_id);
        $this->assertSame($serviceB->id, $section->fresh()->catalog_item_id);
        $this->assertSame($serviceB->id, $leaf->fresh()->catalog_item_id);
    }

    public function test_inline_children_are_saved_and_a_child_removed_from_the_form_is_deactivated_not_deleted(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);
        $manager = app(AnalysisCatalogManager::class);

        $rootData = $this->definition($service, 'PANEL', 'PARENT', 'Bilan complet');
        $rootData['children'] = [
            ['code' => 'PANEL-A', 'level' => 'CHILD', 'designation' => 'Élément A', 'result_type' => 'NUMERIC', 'display_order' => 1, 'exam_category' => 'BIOCHIMIE', 'is_bold' => true],
            ['code' => 'PANEL-B', 'level' => 'CHILD', 'designation' => 'Élément B', 'result_type' => 'NUMERIC', 'display_order' => 2],
        ];
        $root = $manager->saveWithChildren(null, $rootData, $actor);

        $childA = AnalysisCatalog::query()->where('code', 'PANEL-A')->firstOrFail();
        $childB = AnalysisCatalog::query()->where('code', 'PANEL-B')->firstOrFail();
        $this->assertTrue($childA->parent->is($root));
        $this->assertSame($root->catalog_item_id, $childA->catalog_item_id);
        $this->assertSame('BIOCHIMIE', $childA->exam_category);
        $this->assertTrue($childA->is_bold);

        // Re-save with B removed and a brand-new C added — B must be
        // deactivated (still in the database, never hard-deleted, ADR-010),
        // A keeps its uuid, C is created fresh.
        $updateData = $this->definition($service, 'PANEL', 'PARENT', 'Bilan complet');
        $updateData['children'] = [
            ['uuid' => $childA->uuid, 'code' => 'PANEL-A', 'level' => 'CHILD', 'designation' => 'Élément A', 'result_type' => 'NUMERIC', 'display_order' => 1],
            ['code' => 'PANEL-C', 'level' => 'CHILD', 'designation' => 'Élément C', 'result_type' => 'NUMERIC', 'display_order' => 2],
        ];
        $manager->saveWithChildren($root, $updateData, $actor);

        $this->assertTrue($childA->fresh()->is_active);
        $this->assertFalse($childB->fresh()->is_active);
        $this->assertNotSoftDeleted($childB->fresh());
        $childC = AnalysisCatalog::query()->where('code', 'PANEL-C')->firstOrFail();
        $this->assertTrue($childC->parent->is($root));
    }

    public function test_a_sub_analysis_can_itself_be_a_group_with_its_own_inline_sub_sub_analyses(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);
        $manager = app(AnalysisCatalogManager::class);

        $rootData = $this->definition($service, 'ROOT', 'PARENT', 'Bilan complet');
        $rootData['children'] = [
            [
                'code' => 'ROOT-SUBGROUP', 'level' => 'PARENT', 'designation' => 'Sous-groupe', 'result_type' => 'TEXT', 'display_order' => 1,
                'children' => [
                    ['code' => 'ROOT-SUBGROUP-A', 'level' => 'CHILD', 'designation' => 'Élément profond', 'result_type' => 'NUMERIC', 'display_order' => 1],
                ],
            ],
        ];
        $root = $manager->saveWithChildren(null, $rootData, $actor);

        $subgroup = AnalysisCatalog::query()->where('code', 'ROOT-SUBGROUP')->firstOrFail();
        $grandchild = AnalysisCatalog::query()->where('code', 'ROOT-SUBGROUP-A')->firstOrFail();
        $this->assertTrue($subgroup->parent->is($root));
        $this->assertSame('PARENT', $subgroup->level);
        $this->assertTrue($grandchild->parent->is($subgroup));
        $this->assertSame($root->catalog_item_id, $grandchild->catalog_item_id);

        // Re-save without the grandchild — it must be deactivated, not
        // deleted, exactly like a direct child (ADR-010).
        $updateData = $this->definition($service, 'ROOT', 'PARENT', 'Bilan complet');
        $updateData['children'] = [
            ['uuid' => $subgroup->uuid, 'code' => 'ROOT-SUBGROUP', 'level' => 'PARENT', 'designation' => 'Sous-groupe', 'result_type' => 'TEXT', 'display_order' => 1, 'children' => []],
        ];
        $manager->saveWithChildren($root, $updateData, $actor);

        $this->assertFalse($grandchild->fresh()->is_active);
        $this->assertNotSoftDeleted($grandchild->fresh());
    }

    public function test_inline_children_reject_a_code_already_used_elsewhere(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);
        $manager = app(AnalysisCatalogManager::class);
        $manager->create($this->definition($service, 'TAKEN', 'NORMAL', 'Déjà pris'), $actor);

        $rootData = $this->definition($service, 'PANEL2', 'PARENT', 'Bilan');
        $rootData['children'] = [
            ['code' => 'TAKEN', 'level' => 'CHILD', 'designation' => 'Collision', 'result_type' => 'NUMERIC', 'display_order' => 1],
        ];

        try {
            $manager->saveWithChildren(null, $rootData, $actor);
            $this->fail('Une collision de code aurait dû être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('children.0.code', $exception->errors());
        }

        $this->assertDatabaseMissing('analysis_catalogs', ['code' => 'PANEL2']);
    }

    public function test_a_group_and_its_inline_sub_analyses_can_be_created_through_the_web_form(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);

        $payload = $this->definition($service, 'NFS', 'PARENT', 'Numération formule sanguine');
        $payload['exam_category'] = 'HEMATOLOGIE';
        $payload['is_bold'] = true;
        $payload['children'] = [
            ['code' => 'NFS-HB', 'level' => 'CHILD', 'designation' => 'Hémoglobine', 'result_type' => 'NUMERIC', 'reference_male' => '13-17', 'unit' => 'g/dL', 'display_order' => 1],
            ['code' => 'NFS-HT', 'level' => 'CHILD', 'designation' => 'Hématocrite', 'result_type' => 'NUMERIC', 'display_order' => 2],
        ];

        $this->actingAs($actor)->post('/administration/analyses', $payload)->assertRedirect();

        $root = AnalysisCatalog::query()->where('code', 'NFS')->firstOrFail();
        $this->assertSame('HEMATOLOGIE', $root->exam_category);
        $this->assertTrue($root->is_bold);
        $this->assertCount(2, $root->children);
        $this->assertTrue(AnalysisCatalog::query()->where('code', 'NFS-HB')->firstOrFail()->parent->is($root));

        $this->actingAs($actor)
            ->get('/administration/analyses/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('examCategories', ['HEMATOLOGIE']));
    }

    public function test_a_nested_sub_group_can_be_created_through_the_web_form_and_reopened_with_its_grandchildren(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);

        $payload = $this->definition($service, 'DEEP', 'PARENT', 'Bilan profond');
        $payload['children'] = [
            [
                'code' => 'DEEP-GROUP', 'level' => 'PARENT', 'designation' => 'Sous-groupe', 'result_type' => 'TEXT', 'display_order' => 1,
                'children' => [
                    ['code' => 'DEEP-GROUP-A', 'level' => 'CHILD', 'designation' => 'Profond A', 'result_type' => 'NUMERIC', 'display_order' => 1],
                ],
            ],
        ];

        $this->actingAs($actor)->post('/administration/analyses', $payload)->assertRedirect();

        $root = AnalysisCatalog::query()->where('code', 'DEEP')->firstOrFail();

        $this->actingAs($actor)
            ->get("/administration/analyses/{$root->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analysis.children.0.code', 'DEEP-GROUP')
                ->where('analysis.children.0.children.0.code', 'DEEP-GROUP-A'));
    }

    public function test_opening_an_analysis_for_edition_returns_the_current_database_state_not_a_stale_copy(): void
    {
        $actor = $this->administrationUser();
        $service = $this->laboratoryService($actor);
        $otherService = CatalogItem::query()->create([
            'code' => 'LAB-OTHER', 'name' => 'Autre prestation',
            'type' => CatalogItemType::Service->value, 'module' => CatalogModule::Laboratory->value,
            'unit' => 'analyse', 'billable' => true, 'stockable' => false,
        ]);
        $manager = app(AnalysisCatalogManager::class);

        $rootData = $this->definition($service, 'PANEL3', 'PARENT', 'Bilan');
        $rootData['children'] = [
            ['code' => 'PANEL3-A', 'level' => 'CHILD', 'designation' => 'Élément A', 'result_type' => 'NUMERIC', 'display_order' => 1],
        ];
        $root = $manager->saveWithChildren(null, $rootData, $actor);
        $child = AnalysisCatalog::query()->where('code', 'PANEL3-A')->firstOrFail();

        // Simulate a correction made outside of this browser tab (e.g. a
        // direct data-repair script, exactly like the legacy import that
        // once bypassed AnalysisCatalogManager's own business rules and
        // left a child pointing at a different prestation than its parent)
        // — a full page load of /edit must always reflect the database as
        // it stands right now, never a value cached from an earlier visit.
        $child->update(['catalog_item_id' => $otherService->id]);

        $this->actingAs($actor)
            ->get("/administration/analyses/{$child->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analysis.catalog_item.uuid', $otherService->uuid));

        $this->actingAs($actor)
            ->get("/administration/analyses/{$root->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analysis.children.0.uuid', $child->uuid));
    }

    private function administrationUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id'),
        ]);
    }

    private function laboratoryService(User $actor): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => 'LAB-GLYC', 'name' => 'Glycémie',
            'type' => CatalogItemType::Service->value, 'module' => CatalogModule::Laboratory->value,
            'unit' => 'analyse', 'billable' => true, 'stockable' => false,
            'reception_selectable' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function definition(
        CatalogItem $service,
        string $code,
        string $level,
        string $designation,
        ?string $parentUuid = null,
    ): array {
        return [
            'catalog_item_uuid' => $service->uuid,
            'parent_uuid' => $parentUuid,
            'code' => $code,
            'level' => $level,
            'designation' => $designation,
            'description' => null,
            'result_type' => 'TEXT',
            'reference_general' => null,
            'reference_male' => null,
            'reference_female' => null,
            'reference_child_male' => null,
            'reference_child_female' => null,
            'unit' => null,
            'predefined_values' => [],
            'display_order' => 1,
            'is_active' => true,
        ];
    }
}
