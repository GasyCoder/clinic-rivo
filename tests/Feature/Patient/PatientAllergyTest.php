<?php

namespace Tests\Feature\Patient;

use App\Enums\AllergySeverity;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientAllergyTest extends TestCase
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

    public function test_an_allergy_receives_a_uuid_automatically(): void
    {
        $allergy = $this->makePatient()->allergies()->create(['substance' => 'Pénicilline']);

        $this->assertNotNull($allergy->uuid);
    }

    public function test_severity_is_cast_to_the_allergy_severity_enum(): void
    {
        $allergy = $this->makePatient()->allergies()->create([
            'substance' => 'Pénicilline',
            'severity' => 'SEVERE',
        ]);

        $this->assertSame(AllergySeverity::Severe, $allergy->severity);
    }

    public function test_severity_and_reaction_are_optional(): void
    {
        $allergy = $this->makePatient()->allergies()->create(['substance' => 'Arachides']);

        $this->assertNull($allergy->severity);
        $this->assertNull($allergy->reaction);
    }

    public function test_belongs_to_a_patient(): void
    {
        $patient = $this->makePatient();
        $allergy = $patient->allergies()->create(['substance' => 'Pénicilline']);

        $this->assertTrue($allergy->patient->is($patient));
        $this->assertTrue($patient->allergies->first()->is($allergy));
    }

    public function test_recording_an_allergy_is_audited_under_the_medical_module(): void
    {
        $allergy = $this->makePatient()->allergies()->create(['substance' => 'Pénicilline']);

        $log = AuditLog::where('action', 'create')
            ->where('entity_type', PatientAllergy::class)
            ->where('entity_id', $allergy->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('medical', $log->module);
    }

    public function test_a_patient_with_allergies_is_force_delete_protected(): void
    {
        $patient = $this->makePatient();
        $patient->allergies()->create(['substance' => 'Pénicilline']);

        $this->assertTrue($patient->isForceDeleteProtected());
    }
}
