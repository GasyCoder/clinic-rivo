<?php

namespace Tests\Feature\Http;

use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientControllerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'RECEPTION'], ['name' => 'Réception']);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patientData(array $overrides = []): array
    {
        return [
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            ...$overrides,
        ];
    }

    public function test_index_requires_the_patients_view_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie'])->id]);

        $this->actingAs($user)->get('/patients')->assertForbidden();
    }

    public function test_index_lists_patients_and_supports_search(): void
    {
        $user = $this->userWithPermissions(['patients.view']);
        Patient::create(['patient_number' => 'M-000001', ...$this->patientData(['first_name' => 'Jean', 'last_name' => 'Rakoto'])]);
        Patient::create(['patient_number' => 'M-000002', ...$this->patientData(['first_name' => 'Marie', 'last_name' => 'Rasoa'])]);

        $this->actingAs($user)->get('/patients?q=Rakoto')
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Index')
                ->has('patients.data', 1)
                ->where('patients.data.0.last_name', 'Rakoto')
            );
    }

    public function test_index_marks_a_patient_with_an_active_emergency_episode(): void
    {
        $user = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'priority' => 'EMERGENCY',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get('/patients')
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Index')
                ->where('patients.data.0.uuid', $patient->uuid)
                ->where('patients.data.0.active_emergency_episodes_count', 1)
            );
    }

    public function test_there_is_no_creation_route_on_the_read_only_directory(): void
    {
        $user = $this->userWithPermissions(['patients.view']);

        $this->actingAs($user)->post('/patients', $this->patientData())->assertStatus(405);
    }

    public function test_show_loads_antecedents_allergies_and_episodes(): void
    {
        $user = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);
        $patient->antecedents()->create(['description' => 'Diabète type 2']);
        $patient->allergies()->create(['substance' => 'Pénicilline']);

        $this->actingAs($user)->get("/patients/{$patient->uuid}")
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Show')
                ->where('patient.id', $patient->id)
                ->where('patient.uuid', $patient->uuid)
                ->has('patient.antecedents', 1)
                ->has('patient.allergies', 1)
            );
    }

    public function test_show_does_not_resolve_a_patient_from_its_local_numeric_id(): void
    {
        $user = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($user)->get("/patients/{$patient->id}")->assertNotFound();
    }

    public function test_edit_and_update_require_the_patients_update_permission(): void
    {
        $viewer = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($viewer)->get("/patients/{$patient->uuid}/edit")->assertForbidden();
        $this->actingAs($viewer)->put("/patients/{$patient->uuid}", $this->patientData())->assertForbidden();
    }

    public function test_an_authorized_user_can_edit_and_update_a_patient_by_uuid(): void
    {
        $user = $this->userWithPermissions(['patients.update']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($user)->get("/patients/{$patient->uuid}/edit")
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Edit')
                ->where('patient.uuid', $patient->uuid)
                ->where('patient.patient_number', 'M-000001')
            );

        $this->actingAs($user)->put("/patients/{$patient->uuid}", [
            ...$this->patientData([
                'first_name' => 'Marie',
                'last_name' => 'Rakotomalala',
                'birth_date' => '1991-06-13',
                'sex' => 'F',
            ]),
            'phone' => '0340000000',
        ])->assertRedirect("/patients/{$patient->uuid}");

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'first_name' => 'Marie',
            'last_name' => 'Rakotomalala',
            'phone' => '0340000000',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'module' => 'reception',
            'entity_type' => Patient::class,
            'entity_id' => $patient->id,
        ]);
    }

    public function test_patient_update_rejects_an_invalid_administrative_identity(): void
    {
        $user = $this->userWithPermissions(['patients.update']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($user)->put("/patients/{$patient->uuid}", [
            'last_name' => '',
            'birth_date' => '2030-01-01',
            'sex' => 'X',
        ])->assertSessionHasErrors(['last_name', 'birth_date', 'sex']);

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'last_name' => 'Rakoto',
            'sex' => 'M',
        ]);
    }

    public function test_delete_requires_permission_and_a_reason_then_soft_deletes_and_audits(): void
    {
        $viewer = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($viewer)->delete("/patients/{$patient->uuid}", ['reason' => 'Dossier en double'])
            ->assertForbidden();

        $deleter = $this->userWithPermissions(['patients.delete']);
        $this->actingAs($deleter)->delete("/patients/{$patient->uuid}")
            ->assertSessionHasErrors('reason');

        $this->actingAs($deleter)->delete("/patients/{$patient->uuid}", ['reason' => 'Dossier en double'])
            ->assertRedirect();

        $this->assertSoftDeleted('patients', ['id' => $patient->id]);
        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'deleted_by' => $deleter->id,
            'delete_reason' => 'Dossier en double',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete',
            'module' => 'reception',
            'entity_type' => Patient::class,
            'entity_id' => $patient->id,
            'reason' => 'Dossier en double',
        ]);
    }

    public function test_bulk_delete_soft_deletes_and_audits_every_selected_patient(): void
    {
        $user = $this->userWithPermissions(['patients.delete']);
        $first = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);
        $second = Patient::create(['patient_number' => 'M-000002', ...$this->patientData(['last_name' => 'Rasoa'])]);
        $untouched = Patient::create(['patient_number' => 'M-000003', ...$this->patientData(['last_name' => 'Rabe'])]);

        $this->actingAs($user)->post('/patients/bulk-delete', [
            'patient_uuids' => [$first->uuid, $second->uuid],
            'reason' => 'Import en double',
        ])->assertRedirect();

        $this->assertSoftDeleted('patients', ['id' => $first->id]);
        $this->assertSoftDeleted('patients', ['id' => $second->id]);
        $this->assertDatabaseHas('patients', ['id' => $untouched->id, 'deleted_at' => null]);
        $this->assertSame(2, AuditLog::query()
            ->where('action', 'delete')
            ->where('module', 'reception')
            ->where('reason', 'Import en double')
            ->count());
    }

    public function test_bulk_delete_requires_permission_and_valid_patient_uuids(): void
    {
        $viewer = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($viewer)->post('/patients/bulk-delete', [
            'patient_uuids' => [$patient->uuid],
            'reason' => 'Dossier en double',
        ])->assertForbidden();

        $deleter = $this->userWithPermissions(['patients.delete']);
        $this->actingAs($deleter)->post('/patients/bulk-delete', [
            'patient_uuids' => ['uuid-invalide'],
            'reason' => 'Dossier en double',
        ])->assertSessionHasErrors('patient_uuids.0');

        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'deleted_at' => null]);
    }
}
