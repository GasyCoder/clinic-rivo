<?php

namespace Tests\Feature\Surgery;

use App\Actions\Surgery\CreateSurgicalRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\SurgicalRequestOrigin;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SurgicalRequest;
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

    /**
     * ADR-159 — le bloc ne crée plus de demande : il corrige celle qu'il a
     * reçue. Les trois garanties du catalogue (instantané du libellé,
     * « Autres » à préciser, module respecté) valent donc désormais sur la
     * correction, et sont vérifiées ici sur ce chemin.
     */
    private function pendingRequest(Episode $episode, CatalogItem $procedure): SurgicalRequest
    {
        return $this->actingAs($this->surgeon)->app->make(CreateSurgicalRequestAction::class)->execute($episode, [
            'catalog_item_id' => $procedure->id,
            'procedure_name' => $procedure->name,
        ], SurgicalRequestOrigin::Reception);
    }

    public function test_the_block_cannot_open_a_file_of_its_own(): void
    {
        $episode = $this->episode();
        $procedure = $this->procedure('SURG-APPENDICITE', 'Appendicite');

        // L'URL reste valide et ramène à la file, jamais une page disparue.
        $this->actingAs($this->surgeon)->get('/surgery/create')->assertRedirect('/surgery');
        $this->actingAs($this->surgeon)->post('/surgery', [
            'episode_uuid' => $episode->uuid,
            'catalog_item_uuid' => $procedure->uuid,
        ])->assertStatus(405);

        $this->assertDatabaseCount('surgical_requests', 0);
    }

    public function test_correcting_a_request_selects_a_controlled_procedure_and_keeps_its_snapshot(): void
    {
        $episode = $this->episode();
        $planned = $this->procedure('SURG-ABCES', 'Abcès');
        $procedure = $this->procedure('SURG-APPENDICITE', 'Appendicite');
        $request = $this->pendingRequest($episode, $planned);

        $this->actingAs($this->surgeon)->put("/surgery/{$request->uuid}", [
            'catalog_item_uuid' => $procedure->uuid,
            'notes' => 'Corrigé au bloc',
        ])->assertRedirect();

        $request->refresh();
        $this->assertSame($procedure->id, $request->catalog_item_id);
        $this->assertSame('Appendicite', $request->procedure_name);
        $this->assertDatabaseCount('billable_items', 0);

        $procedure->update(['name' => 'Appendicite — libellé corrigé']);
        $this->assertSame('Appendicite', $request->fresh()->procedure_name);
    }

    public function test_other_procedure_requires_a_clinical_description(): void
    {
        $episode = $this->episode();
        $planned = $this->procedure('SURG-ABCES', 'Abcès');
        $other = $this->procedure('SURG-OTHER', 'Autres');
        $request = $this->pendingRequest($episode, $planned);

        $this->actingAs($this->surgeon)->put("/surgery/{$request->uuid}", [
            'catalog_item_uuid' => $other->uuid,
            'procedure_details' => '',
        ])->assertSessionHasErrors('procedure_details');

        $this->assertSame($planned->id, $request->fresh()->catalog_item_id);

        $this->actingAs($this->surgeon)->put("/surgery/{$request->uuid}", [
            'catalog_item_uuid' => $other->uuid,
            'procedure_details' => 'Drainage d’un abcès profond',
        ])->assertRedirect();

        $this->assertDatabaseHas('surgical_requests', [
            'id' => $request->id,
            'catalog_item_id' => $other->id,
            'procedure_name' => 'Autres',
            'procedure_details' => 'Drainage d’un abcès profond',
        ]);
    }

    public function test_request_rejects_a_surg_prefixed_item_from_another_module(): void
    {
        $episode = $this->episode();
        $planned = $this->procedure('SURG-ABCES', 'Abcès');
        $wrongModule = $this->procedure('SURG-INVALID', 'Mauvais domaine', CatalogModule::Medicine);
        $request = $this->pendingRequest($episode, $planned);

        $this->actingAs($this->surgeon)->put("/surgery/{$request->uuid}", [
            'catalog_item_uuid' => $wrongModule->uuid,
        ])->assertSessionHasErrors('catalog_item_uuid');

        $this->assertSame($planned->id, $request->fresh()->catalog_item_id);
    }
}
