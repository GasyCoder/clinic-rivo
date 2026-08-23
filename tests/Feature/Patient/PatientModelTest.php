<?php

namespace Tests\Feature\Patient;

use App\Enums\IdentityDocumentType;
use App\Enums\PatientCivility;
use App\Enums\PatientSex;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientModelTest extends TestCase
{
    use RefreshDatabase;

    private function makePatient(array $overrides = []): Patient
    {
        return Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            ...$overrides,
        ]);
    }

    public function test_a_patient_receives_a_uuid_automatically(): void
    {
        $patient = $this->makePatient();

        $this->assertNotNull($patient->uuid);
    }

    public function test_sex_is_cast_to_the_patient_sex_enum(): void
    {
        $patient = $this->makePatient(['sex' => 'F']);

        $this->assertSame(PatientSex::Female, $patient->sex);
    }

    public function test_birth_date_is_cast_to_a_date(): void
    {
        $patient = $this->makePatient();

        $this->assertSame('1990-05-12', $patient->birth_date->toDateString());
    }

    public function test_creating_a_patient_is_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = $this->makePatient();

        $log = AuditLog::where('action', 'create')
            ->where('entity_type', Patient::class)
            ->where('entity_id', $patient->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('reception', $log->module);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_updating_a_patient_is_audited_with_old_and_new_values(): void
    {
        $patient = $this->makePatient();

        $patient->update(['phone' => '0341234567']);

        $log = AuditLog::where('action', 'update')
            ->where('entity_type', Patient::class)
            ->where('entity_id', $patient->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['phone' => '0341234567'], $log->new_values);
        $this->assertSame(['phone' => null], $log->old_values);
    }

    public function test_deleting_a_patient_soft_deletes_and_is_audited_once_not_twice(): void
    {
        $patient = $this->makePatient();
        $patient->delete_reason = 'Doublon confirmé';
        $patient->delete();

        $this->assertSoftDeleted($patient);
        $this->assertSame(1, AuditLog::where('action', 'delete')->count());
        $this->assertSame(0, AuditLog::where('action', 'update')
            ->where('entity_type', Patient::class)
            ->count());
    }

    public function test_restoring_a_patient_is_audited_once_not_twice(): void
    {
        $patient = $this->makePatient();
        $patient->delete();

        $patient->restore();

        $this->assertSame(1, AuditLog::where('action', 'restore')->count());
        $this->assertSame(0, AuditLog::where('action', 'update')
            ->where('entity_type', Patient::class)
            ->count());
    }

    public function test_patient_is_not_force_delete_protected_by_default(): void
    {
        $patient = $this->makePatient();

        $this->assertFalse($patient->isForceDeleteProtected());
    }

    public function test_civility_is_cast_to_the_patient_civility_enum(): void
    {
        $patient = $this->makePatient(['civility' => 'MRS']);

        $this->assertSame(PatientCivility::Mrs, $patient->civility);
    }

    public function test_identity_document_fields_are_stored_and_cast(): void
    {
        $patient = $this->makePatient([
            'identity_document_type' => 'CIN',
            'identity_document_number' => '101234567890',
        ]);

        $this->assertSame(IdentityDocumentType::Cin, $patient->identity_document_type);
        $this->assertSame('101234567890', $patient->identity_document_number);
    }

    public function test_email_fields_are_stored(): void
    {
        $patient = $this->makePatient([
            'email' => 'jean.rakoto@example.mg',
        ]);

        $this->assertSame('jean.rakoto@example.mg', $patient->email);
    }
}
