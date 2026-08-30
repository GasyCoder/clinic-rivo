<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Models\DiagnosticCatalog;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagnosticCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_searches_active_catalog_by_name_or_code_only(): void
    {
        $doctor = $this->userWithPermissions('MEDICINE', ['diagnoses.create']);
        DiagnosticCatalog::query()->create(['code' => 'K35', 'name' => 'Appendicite aiguë', 'is_active' => true]);
        DiagnosticCatalog::query()->create(['code' => 'K36', 'name' => 'Autre appendicite', 'is_active' => false]);

        $this->actingAs($doctor)->getJson('/diagnostic-catalog/search?q=append')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Appendicite aiguë');

        $this->getJson('/diagnostic-catalog/search?q=K35')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'K35');
    }

    public function test_catalog_diagnosis_records_server_snapshots_type_and_notes(): void
    {
        $doctor = $this->userWithPermissions('MEDICINE', [
            'consultations.create', 'diagnoses.create', 'diagnoses.view', 'consultations.view',
        ]);
        $orientation = $this->activeOrientation($doctor);
        $catalog = DiagnosticCatalog::query()->create([
            'code' => 'J18',
            'name' => 'Pneumonie communautaire',
            'category' => 'Respiratoire',
            'is_active' => true,
        ]);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'HYPOTHESIS',
            'diagnostic_catalog_uuid' => $catalog->uuid,
            'notes' => 'À confirmer par imagerie.',
            'name' => 'Libellé falsifié',
            'code' => 'FAUX',
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/diagnostic");

        $diagnosis = $orientation->consultation->diagnoses()->sole();
        $this->assertSame('HYPOTHESIS', $diagnosis->type->value);
        $this->assertSame($catalog->id, $diagnosis->diagnostic_catalog_id);
        $this->assertSame('Pneumonie communautaire', $diagnosis->description);
        $this->assertSame('Pneumonie communautaire', $diagnosis->catalog_name_snapshot);
        $this->assertSame('J18', $diagnosis->catalog_code_snapshot);
        $this->assertSame('À confirmer par imagerie.', $diagnosis->notes);
        $this->assertFalse($diagnosis->is_manual);
    }

    public function test_catalog_changes_do_not_rewrite_historical_diagnosis_and_inactive_entry_remains_visible(): void
    {
        $doctor = $this->userWithPermissions('MEDICINE', [
            'consultations.create', 'consultations.view', 'diagnoses.create', 'diagnoses.view',
        ]);
        $orientation = $this->activeOrientation($doctor);
        $catalog = DiagnosticCatalog::query()->create(['code' => 'I10', 'name' => 'Hypertension artérielle', 'is_active' => true]);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'diagnostic_catalog_uuid' => $catalog->uuid,
        ]);
        $catalog->update(['code' => 'I10-N', 'name' => 'Hypertension renommée', 'is_active' => false]);

        $diagnosis = $orientation->consultation->diagnoses()->sole();
        $this->assertSame('Hypertension artérielle', $diagnosis->fresh()->description);
        $this->assertSame('I10', $diagnosis->catalog_code_snapshot);

        $this->get("/medicine/orientations/{$orientation->uuid}/diagnostic")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.diagnoses.0.description', 'Hypertension artérielle')
                ->where('consultation.diagnoses.0.code', 'I10')
                ->where('consultation.diagnoses.0.source', 'CATALOG'));
    }

    public function test_manual_hypothesis_and_final_can_coexist_without_creating_catalog_entries(): void
    {
        $doctor = $this->userWithPermissions('MEDICINE', ['consultations.create', 'diagnoses.create']);
        $orientation = $this->activeOrientation($doctor);

        foreach ([
            ['type' => 'HYPOTHESIS', 'description' => 'Syndrome abdominal atypique', 'manual_code' => 'LOC-1'],
            ['type' => 'FINAL', 'description' => 'Déshydratation modérée', 'notes' => 'Sur pertes digestives.'],
        ] as $payload) {
            $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", $payload)
                ->assertSessionHasNoErrors();
        }

        $diagnoses = $orientation->consultation->diagnoses()->orderBy('id')->get();
        $this->assertCount(2, $diagnoses);
        $this->assertSame(['HYPOTHESIS', 'FINAL'], $diagnoses->pluck('type')->map->value->all());
        $this->assertTrue($diagnoses->every->is_manual);
        $this->assertDatabaseCount('diagnostic_catalogs', 0);
    }

    public function test_immediate_duplicate_catalog_submit_is_rejected_but_cancelled_history_does_not_block_reentry(): void
    {
        $doctor = $this->userWithPermissions('MEDICINE', [
            'consultations.create', 'diagnoses.create', 'diagnoses.update',
        ]);
        $orientation = $this->activeOrientation($doctor);
        $catalog = DiagnosticCatalog::query()->create(['name' => 'Gastro-entérite', 'is_active' => true]);
        $payload = ['type' => 'FINAL', 'diagnostic_catalog_uuid' => $catalog->uuid];

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", $payload)
            ->assertSessionHasNoErrors();
        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses", $payload)
            ->assertSessionHasErrors('diagnostic_catalog_uuid');
        $this->assertDatabaseCount('diagnoses', 1);

        $diagnosis = $orientation->consultation->diagnoses()->sole();
        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses/cancel", ['diagnosis_id' => $diagnosis->id])
            ->assertSessionHasNoErrors();
        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses", $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('diagnoses', 2);
    }

    public function test_inactive_catalog_and_user_without_permission_cannot_create_diagnosis(): void
    {
        $doctor = $this->userWithPermissions('MEDICINE', ['consultations.create', 'diagnoses.create']);
        $orientation = $this->activeOrientation($doctor);
        $inactive = DiagnosticCatalog::query()->create(['name' => 'Diagnostic retiré', 'is_active' => false]);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'diagnostic_catalog_uuid' => $inactive->uuid,
        ])->assertSessionHasErrors('diagnostic_catalog_uuid');

        $unauthorized = $this->userWithPermissions('NURSE', []);
        $this->actingAs($unauthorized)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Interdit',
        ])->assertForbidden();
        $this->assertDatabaseCount('diagnoses', 0);
    }

    public function test_administration_can_create_update_and_deactivate_catalog_but_medicine_cannot_manage_it(): void
    {
        $admin = $this->userWithPermissions('ADMINISTRATION', [
            'diagnostic_catalog.view', 'diagnostic_catalog.manage',
        ]);

        $this->actingAs($admin)->post('/administration/diagnostics', [
            'code' => ' k35 ',
            'name' => ' Appendicite aiguë ',
            'category' => 'Digestif',
        ])->assertSessionHasNoErrors();

        $catalog = DiagnosticCatalog::query()->sole();
        $this->assertSame('K35', $catalog->code);
        $this->put("/administration/diagnostics/{$catalog->uuid}", [
            'code' => 'K35.8',
            'name' => 'Autres appendicites',
            'description' => 'Référentiel local.',
        ])->assertSessionHasNoErrors();
        $this->post("/administration/diagnostics/{$catalog->uuid}/deactivate")
            ->assertSessionHasNoErrors();
        $this->assertFalse($catalog->fresh()->is_active);

        $doctor = $this->userWithPermissions('MEDICINE', ['diagnoses.create']);
        $this->actingAs($doctor)->post('/administration/diagnostics', [
            'name' => 'Création interdite',
        ])->assertForbidden();
    }

    private function userWithPermissions(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function activeOrientation(User $doctor): EpisodeOrientation
    {
        $this->actingAs($doctor);
        $patient = Patient::query()->create([
            'patient_number' => 'A-26-'.str_pad((string) (Patient::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1992-05-12',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient, EpisodePriority::Emergency);
        $orientation = $episode->orientations()->where('destination_module', CatalogModule::Medicine->value)->sole();

        $this->post("/medicine/orientations/{$orientation->uuid}/accept")->assertRedirect();

        return $orientation->fresh(['consultation']);
    }
}
