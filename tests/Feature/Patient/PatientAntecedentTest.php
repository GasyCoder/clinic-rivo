<?php

namespace Tests\Feature\Patient;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientAntecedent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientAntecedentTest extends TestCase
{
    use RefreshDatabase;

    private function makePatient(): Patient
    {
        return Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    public function test_an_antecedent_receives_a_uuid_automatically(): void
    {
        $antecedent = $this->makePatient()->antecedents()->create(['description' => 'Diabète type 2']);

        $this->assertNotNull($antecedent->uuid);
    }

    public function test_belongs_to_a_patient(): void
    {
        $patient = $this->makePatient();
        $antecedent = $patient->antecedents()->create(['description' => 'Diabète type 2']);

        $this->assertTrue($antecedent->patient->is($patient));
        $this->assertTrue($patient->antecedents->first()->is($antecedent));
    }

    public function test_recording_an_antecedent_is_audited_under_the_medical_module(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $antecedent = $this->makePatient()->antecedents()->create(['description' => 'Diabète type 2']);

        $log = AuditLog::where('action', 'create')
            ->where('entity_type', PatientAntecedent::class)
            ->where('entity_id', $antecedent->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('medical', $log->module);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_removing_an_antecedent_soft_deletes_with_a_reason_and_keeps_history(): void
    {
        $antecedent = $this->makePatient()->antecedents()->create(['description' => 'Erreur de saisie']);
        $antecedent->delete_reason = 'Saisi par erreur sur le mauvais patient';
        $antecedent->delete();

        $this->assertSoftDeleted($antecedent);
        $this->assertSame(1, AuditLog::where('action', 'delete')
            ->where('entity_type', PatientAntecedent::class)
            ->count());
    }

    public function test_a_patient_with_antecedents_is_force_delete_protected(): void
    {
        $patient = $this->makePatient();
        $patient->antecedents()->create(['description' => 'Diabète type 2']);

        $this->assertTrue($patient->isForceDeleteProtected());
    }

    private function userWithPermission(string $permission, string $roleCode = 'NURSE'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);
        $permissionModel = Permission::query()->firstOrCreate(['name' => $permission]);
        $role->permissions()->syncWithoutDetaching([$permissionModel->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_an_authorized_user_can_add_an_antecedent_through_the_generic_patient_endpoint(): void
    {
        $user = $this->userWithPermission('patients.medical_history.manage');
        $patient = $this->makePatient();

        $this->actingAs($user)
            ->post("/patients/{$patient->uuid}/antecedents", ['description' => 'Diabète type 2'])
            ->assertRedirect();

        $antecedent = PatientAntecedent::query()->sole();
        $this->assertSame('Diabète type 2', $antecedent->description);
        $this->assertSame($patient->id, $antecedent->patient_id);
        $this->assertSame($user->id, $antecedent->recorded_by);
    }

    public function test_a_user_without_the_permission_cannot_add_an_antecedent(): void
    {
        $user = $this->userWithPermission('patients.view');
        $patient = $this->makePatient();

        $this->actingAs($user)
            ->post("/patients/{$patient->uuid}/antecedents", ['description' => 'Diabète type 2'])
            ->assertForbidden();

        $this->assertDatabaseCount('patient_antecedents', 0);
    }

    public function test_adding_an_antecedent_does_not_create_or_modify_any_consultation(): void
    {
        $user = $this->userWithPermission('patients.medical_history.manage');
        $patient = $this->makePatient();

        $this->actingAs($user)
            ->post("/patients/{$patient->uuid}/antecedents", ['description' => 'Diabète type 2'])
            ->assertRedirect();

        $this->assertDatabaseCount('consultations', 0);
        $this->assertSame(0, Consultation::query()->count());
    }

    /**
     * RecordPatientAntecedentAction has no dedup/idempotency policy of its
     * own (each call plainly appends a row) — this pins that current
     * behavior down rather than inventing a uniqueness rule that isn't
     * actually implemented.
     */
    public function test_the_action_has_no_deduplication_policy_and_two_identical_submissions_both_persist(): void
    {
        $user = $this->userWithPermission('patients.medical_history.manage');
        $patient = $this->makePatient();

        $this->actingAs($user)->post("/patients/{$patient->uuid}/antecedents", ['description' => 'Diabète type 2']);
        $this->actingAs($user)->post("/patients/{$patient->uuid}/antecedents", ['description' => 'Diabète type 2']);

        $this->assertSame(2, $patient->antecedents()->count());
    }
}
