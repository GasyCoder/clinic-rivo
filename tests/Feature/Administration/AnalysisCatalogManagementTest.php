<?php

namespace Tests\Feature\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
