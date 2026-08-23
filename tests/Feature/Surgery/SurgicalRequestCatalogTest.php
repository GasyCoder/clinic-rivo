<?php

namespace Tests\Feature\Surgery;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurgicalRequestCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $surgeon;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->surgeon = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SURGERY')->value('id'),
        ]);
    }

    private function episode(): Episode
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1990-02-12',
            'sex' => 'F',
        ]);

        return Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
        ]);
    }

    private function procedure(string $code, string $name, CatalogModule $module = CatalogModule::Surgery): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => 'intervention',
            'billable' => $code !== 'SURG-OTHER',
            'stockable' => false,
            'reception_selectable' => false,
            'created_by' => $this->surgeon->id,
            'updated_by' => $this->surgeon->id,
        ]);
    }

    public function test_request_selects_a_controlled_procedure_and_keeps_its_clinical_snapshot(): void
    {
        $episode = $this->episode();
        $procedure = $this->procedure('SURG-APPENDICITE', 'Appendicite');

        $this->actingAs($this->surgeon)->post('/surgery', [
            'episode_uuid' => $episode->uuid,
            'catalog_item_uuid' => $procedure->uuid,
            'notes' => 'Demande urgente',
        ])->assertRedirect();

        $request = $episode->surgicalRequests()->sole();
        $this->assertSame($procedure->id, $request->catalog_item_id);
        $this->assertSame('Appendicite', $request->procedure_name);
        $this->assertNull($request->procedure_details);
        $this->assertDatabaseCount('billable_items', 0);

        $procedure->update(['name' => 'Appendicite — libellé corrigé']);
        $this->assertSame('Appendicite', $request->fresh()->procedure_name);
    }

    public function test_other_procedure_requires_a_clinical_description(): void
    {
        $episode = $this->episode();
        $other = $this->procedure('SURG-OTHER', 'Autres');

        $this->actingAs($this->surgeon)->post('/surgery', [
            'episode_uuid' => $episode->uuid,
            'catalog_item_uuid' => $other->uuid,
            'procedure_details' => '',
        ])->assertSessionHasErrors('procedure_details');

        $this->assertDatabaseCount('surgical_requests', 0);

        $this->actingAs($this->surgeon)->post('/surgery', [
            'episode_uuid' => $episode->uuid,
            'catalog_item_uuid' => $other->uuid,
            'procedure_details' => 'Drainage d’un abcès profond',
        ])->assertRedirect();

        $this->assertDatabaseHas('surgical_requests', [
            'catalog_item_id' => $other->id,
            'procedure_name' => 'Autres',
            'procedure_details' => 'Drainage d’un abcès profond',
        ]);
    }

    public function test_request_rejects_a_surg_prefixed_item_from_another_module(): void
    {
        $episode = $this->episode();
        $wrongModule = $this->procedure('SURG-INVALID', 'Mauvais domaine', CatalogModule::Medicine);

        $this->actingAs($this->surgeon)->post('/surgery', [
            'episode_uuid' => $episode->uuid,
            'catalog_item_uuid' => $wrongModule->uuid,
        ])->assertSessionHasErrors('catalog_item_uuid');

        $this->assertDatabaseCount('surgical_requests', 0);
    }
}
