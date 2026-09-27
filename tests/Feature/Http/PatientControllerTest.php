<?php

namespace Tests\Feature\Http;

use App\Enums\PatientType;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_index_exposes_the_age_calculated_from_the_birth_date(): void
    {
        Carbon::setTestNow('2026-08-23 10:00:00');

        $user = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create([
            'patient_number' => 'M-26-0001',
            ...$this->patientData(['birth_date' => '1990-09-12']),
        ]);

        $this->actingAs($user)->get('/patients')
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Index')
                ->where('patients.data.0.uuid', $patient->uuid)
                ->where('patients.data.0.age', 35)
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

    public function test_index_exposes_the_directory_activity_signals_and_summary(): void
    {
        Carbon::setTestNow('2026-09-10 10:00:00');

        $user = $this->userWithPermissions(['patients.view']);
        $visited = Patient::create(['patient_number' => 'M-26-0001', ...$this->patientData(['last_name' => 'Rakoto'])]);
        $neverVisited = Patient::create(['patient_number' => 'M-26-0002', ...$this->patientData(['last_name' => 'Rasoa'])]);

        Episode::create([
            'patient_id' => $visited->id,
            'episode_number' => 'M-26-0001-01',
            'status' => 'CLOSED',
            'priority' => 'NORMAL',
            'administrative_status' => 'ORIENTED',
            'started_at' => '2026-09-01 08:00:00',
            'created_by' => $user->id,
        ]);
        Episode::create([
            'patient_id' => $visited->id,
            'episode_number' => 'M-26-0001-02',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'ORIENTED',
            'started_at' => '2026-09-08 09:30:00',
            'created_by' => $user->id,
        ]);
        // A cancelled passage is not a visit — it must not become the
        // patient's "last visit" nor inflate the passage count.
        Episode::create([
            'patient_id' => $visited->id,
            'episode_number' => 'M-26-0001-03',
            'status' => 'CANCELLED',
            'priority' => 'NORMAL',
            'administrative_status' => 'ORIENTED',
            'started_at' => '2026-09-09 15:00:00',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get('/patients')
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Index')
                ->where('patients.data.1.uuid', $visited->uuid)
                ->where('patients.data.1.open_episodes_count', 1)
                ->where('patients.data.1.episodes_count', 2)
                ->where('patients.data.1.last_visit_at', Carbon::parse('2026-09-08 09:30:00')->toIso8601String())
                ->where('patients.data.0.uuid', $neverVisited->uuid)
                ->where('patients.data.0.open_episodes_count', 0)
                ->where('patients.data.0.last_visit_at', null)
                ->where('summary.total', 2)
                ->where('summary.emergency', 0)
                ->where('summary.in_progress', 1)
                ->where('summary.created_this_month', 2)
            );

        Carbon::setTestNow();
    }

    /**
     * Médecine a conclu, la Réception n'a pas encore prononcé la sortie.
     *
     * Le répertoire affichait « Passage en cours » à un médecin qui venait
     * justement de clôturer : deux écrans qui semblaient se contredire. Ce
     * cas porte désormais son propre compteur, et donc son propre libellé.
     */
    public function test_a_passage_awaiting_settlement_is_counted_apart_from_one_still_in_care(): void
    {
        $user = $this->userWithPermissions(['patients.view']);

        $settling = Patient::create(['patient_number' => 'M-000201', ...$this->patientData()]);
        Episode::create([
            'patient_id' => $settling->id,
            'episode_number' => 'M-26-0201-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'PENDING_SETTLEMENT',
            'started_at' => '2026-09-10 08:00:00',
            'created_by' => $user->id,
        ]);

        $inCare = Patient::create(['patient_number' => 'M-000202', ...$this->patientData()]);
        Episode::create([
            'patient_id' => $inCare->id,
            'episode_number' => 'M-26-0202-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'administrative_status' => 'IN_CARE',
            'started_at' => '2026-09-10 09:00:00',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get('/patients')
            ->assertInertia(function ($page) use ($settling, $inCare) {
                $rows = collect($page->toArray()['props']['patients']['data'])->keyBy('uuid');

                $page->component('Patients/Index');

                // Celui qui n'attend qu'un règlement : compté à part.
                $this->assertSame(1, $rows[$settling->uuid]['open_episodes_count']);
                $this->assertSame(1, $rows[$settling->uuid]['settlement_episodes_count']);

                // Celui encore en soins : aucun règlement en attente.
                $this->assertSame(1, $rows[$inCare->uuid]['open_episodes_count']);
                $this->assertSame(0, $rows[$inCare->uuid]['settlement_episodes_count']);
            });
    }

    public function test_index_supports_the_type_and_emergency_filters(): void
    {
        $user = $this->userWithPermissions(['patients.view']);
        $standard = Patient::create(['patient_number' => 'M-000001', ...$this->patientData(['last_name' => 'Rakoto']), 'patient_type' => PatientType::Standard->value]);
        $mutual = Patient::create(['patient_number' => 'M-000002', ...$this->patientData(['last_name' => 'Rasoa']), 'patient_type' => PatientType::Mutual->value]);
        Episode::create([
            'patient_id' => $standard->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'priority' => 'EMERGENCY',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get('/patients?type=MUTUAL')
            ->assertInertia(fn ($page) => $page
                ->has('patients.data', 1)
                ->where('patients.data.0.uuid', $mutual->uuid)
                ->where('filters.type', 'MUTUAL'));

        $this->actingAs($user)->get('/patients?emergency=active')
            ->assertInertia(fn ($page) => $page
                ->has('patients.data', 1)
                ->where('patients.data.0.uuid', $standard->uuid));

        $this->actingAs($user)->get('/patients?emergency=none')
            ->assertInertia(fn ($page) => $page
                ->has('patients.data', 1)
                ->where('patients.data.0.uuid', $mutual->uuid));

        $this->actingAs($user)->get('/patients?type=bogus')
            ->assertInertia(fn ($page) => $page
                ->has('patients.data', 2)
                ->where('filters.type', null));
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

    public function test_updating_a_patient_with_return_to_reception_redirects_back_to_the_arrival_journey(): void
    {
        $user = $this->userWithPermissions(['patients.update']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($user)->put("/patients/{$patient->uuid}?return_to=reception", [
            ...$this->patientData(['first_name' => 'Marie']),
        ])->assertRedirect('/reception/patients');

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'first_name' => 'Marie',
        ]);
    }

    public function test_updating_a_patient_with_an_unrecognized_return_to_still_redirects_to_the_dossier(): void
    {
        $user = $this->userWithPermissions(['patients.update']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($user)->put("/patients/{$patient->uuid}?return_to=".urlencode('https://evil.example'), [
            ...$this->patientData(['first_name' => 'Marie']),
        ])->assertRedirect("/patients/{$patient->uuid}");
    }

    public function test_a_staff_patient_cannot_be_edited_outside_the_hr_record(): void
    {
        $user = $this->userWithPermissions(['patients.update']);
        $patient = Patient::create([
            'patient_number' => 'M-26-0001',
            'patient_type' => PatientType::Staff,
            ...$this->patientData(),
        ]);

        $this->actingAs($user)
            ->get("/patients/{$patient->uuid}/edit")
            ->assertForbidden();

        $this->actingAs($user)
            ->put("/patients/{$patient->uuid}", [
                ...$this->patientData(['first_name' => 'Modification interdite']),
            ])
            ->assertForbidden();

        $this->assertSame('Jean', $patient->fresh()->first_name);
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
