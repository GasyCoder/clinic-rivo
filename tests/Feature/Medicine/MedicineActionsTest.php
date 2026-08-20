<?php

namespace Tests\Feature\Medicine;

use App\Actions\Medicine\CancelPrescriptionAction;
use App\Actions\Medicine\CreateConsultationAction;
use App\Actions\Medicine\CreatePrescriptionAction;
use App\Actions\Medicine\RecordDiagnosisAction;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\PrescriptionStatus;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeEpisode(EpisodeAdministrativeStatus $status = EpisodeAdministrativeStatus::Oriented): Episode
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
            'administrative_status' => $status,
            'started_at' => now(),
        ]);
    }

    public function test_create_consultation_action_moves_episode_to_in_care(): void
    {
        $doctor = User::factory()->create();
        $this->actingAs($doctor);
        $episode = $this->makeEpisode();

        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, [
            'reason' => 'Douleur abdominale',
        ]);

        $this->assertSame($doctor->id, $consultation->doctor_id);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
    }

    public function test_a_second_consultation_does_not_error_once_already_in_care(): void
    {
        $episode = $this->makeEpisode(EpisodeAdministrativeStatus::InCare);
        $this->actingAs(User::factory()->create());

        $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Suivi']);

        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
    }

    public function test_record_diagnosis_action_records_who_captured_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $episode = $this->makeEpisode();
        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Douleur']);

        $diagnosis = $this->app->make(RecordDiagnosisAction::class)
            ->execute($consultation, DiagnosisType::Final, 'Appendicite aiguë');

        $this->assertSame('Appendicite aiguë', $diagnosis->description);
        $this->assertSame($user->id, $diagnosis->recorded_by);
    }

    public function test_create_prescription_action_creates_lines(): void
    {
        $episode = $this->makeEpisode();
        $this->actingAs(User::factory()->create());
        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Douleur']);

        $prescription = $this->app->make(CreatePrescriptionAction::class)->execute($consultation, [
            ['medication_name' => 'Paracétamol', 'dosage' => '1g', 'frequency' => '3x/jour'],
            ['medication_name' => 'Amoxicilline', 'dosage' => '500mg'],
        ]);

        $this->assertSame(PrescriptionStatus::Active, $prescription->status);
        $this->assertSame(2, $prescription->lines()->count());
    }

    public function test_cancel_prescription_action_cancels_with_a_reason(): void
    {
        $episode = $this->makeEpisode();
        $this->actingAs(User::factory()->create());
        $consultation = $this->app->make(CreateConsultationAction::class)->execute($episode, ['reason' => 'Douleur']);
        $prescription = $this->app->make(CreatePrescriptionAction::class)
            ->execute($consultation, [['medication_name' => 'Paracétamol']]);

        $cancelled = $this->app->make(CancelPrescriptionAction::class)->execute($prescription, 'Erreur de saisie');

        $this->assertSame(PrescriptionStatus::Cancelled, $cancelled->status);
    }
}
