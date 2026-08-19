<?php

namespace Tests\Feature\Medicine;

use App\Enums\ConsultationDecision;
use App\Enums\EpisodeAdministrativeStatus;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeEpisode(): Episode
    {
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);

        return Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'administrative_status' => EpisodeAdministrativeStatus::Oriented,
            'started_at' => now(),
        ]);
    }

    private function makeConsultation(array $overrides = []): Consultation
    {
        $episode = $overrides['episode'] ?? $this->makeEpisode();
        unset($overrides['episode']);

        $doctor = User::factory()->create();

        return $episode->consultations()->create([
            'doctor_id' => $doctor->id,
            'reason' => 'Douleur abdominale',
            'consulted_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_decision_and_consulted_at_are_cast(): void
    {
        $consultation = $this->makeConsultation(['decision' => 'DISCHARGE']);

        $this->assertSame(ConsultationDecision::Discharge, $consultation->decision);
        $this->assertNotNull($consultation->consulted_at);
    }

    public function test_belongs_to_an_episode_and_a_doctor(): void
    {
        $episode = $this->makeEpisode();
        $consultation = $this->makeConsultation(['episode' => $episode]);

        $this->assertTrue($consultation->episode->is($episode));
        $this->assertTrue($consultation->doctor->is(User::find($consultation->doctor_id)));
    }

    public function test_creating_a_consultation_is_audited_under_the_medical_module(): void
    {
        $consultation = $this->makeConsultation();

        $log = AuditLog::where('action', 'create')
            ->where('entity_type', Consultation::class)
            ->where('entity_id', $consultation->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('medical', $log->module);
    }

    public function test_deleting_a_consultation_soft_deletes_and_is_audited_once(): void
    {
        $consultation = $this->makeConsultation();
        $consultation->delete_reason = 'Doublon';
        $consultation->delete();

        $this->assertSoftDeleted($consultation);
        $this->assertSame(1, AuditLog::where('action', 'delete')
            ->where('entity_type', Consultation::class)
            ->count());
    }

    public function test_a_consultation_with_diagnoses_is_force_delete_protected(): void
    {
        $consultation = $this->makeConsultation();
        $consultation->diagnoses()->create([
            'type' => 'HYPOTHESIS',
            'description' => 'Appendicite suspectée',
        ]);

        $this->assertTrue($consultation->isForceDeleteProtected());
    }
}
