<?php

namespace Tests\Feature\Medicine;

use App\Enums\DiagnosisType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagnosisModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeConsultation(): Consultation
    {
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'administrative_status' => EpisodeAdministrativeStatus::Oriented,
            'started_at' => now(),
        ]);

        return $episode->consultations()->create([
            'doctor_id' => User::factory()->create()->id,
            'reason' => 'Douleur abdominale',
            'consulted_at' => now(),
        ]);
    }

    public function test_type_is_cast_to_the_diagnosis_type_enum(): void
    {
        $diagnosis = $this->makeConsultation()->diagnoses()->create([
            'type' => 'HYPOTHESIS',
            'description' => 'Appendicite suspectée',
        ]);

        $this->assertSame(DiagnosisType::Hypothesis, $diagnosis->type);
    }

    public function test_belongs_to_a_consultation(): void
    {
        $consultation = $this->makeConsultation();
        $diagnosis = $consultation->diagnoses()->create([
            'type' => 'FINAL',
            'description' => 'Appendicite aiguë',
        ]);

        $this->assertTrue($diagnosis->consultation->is($consultation));
    }

    public function test_a_second_hypothesis_does_not_overwrite_the_first(): void
    {
        $consultation = $this->makeConsultation();
        $consultation->diagnoses()->create(['type' => 'HYPOTHESIS', 'description' => 'Gastrite']);
        $consultation->diagnoses()->create(['type' => 'HYPOTHESIS', 'description' => 'Appendicite']);
        $consultation->diagnoses()->create(['type' => 'FINAL', 'description' => 'Appendicite aiguë']);

        $this->assertSame(3, $consultation->diagnoses()->count());
        $this->assertSame(1, $consultation->diagnoses()->where('type', 'FINAL')->count());
    }

    public function test_recording_a_diagnosis_is_audited_under_the_medical_module(): void
    {
        $diagnosis = $this->makeConsultation()->diagnoses()->create([
            'type' => 'FINAL',
            'description' => 'Appendicite aiguë',
        ]);

        $log = AuditLog::where('action', 'create')
            ->where('entity_type', Diagnosis::class)
            ->where('entity_id', $diagnosis->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('medical', $log->module);
    }
}
