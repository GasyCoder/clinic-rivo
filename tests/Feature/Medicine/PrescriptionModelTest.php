<?php

namespace Tests\Feature\Medicine;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\PrescriptionStatus;
use App\Exceptions\InvalidPrescriptionTransitionException;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrescriptionModelTest extends TestCase
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

    private function makePrescription(): Prescription
    {
        return $this->makeConsultation()->prescriptions()->create([
            'status' => PrescriptionStatus::Active,
        ]);
    }

    public function test_a_prescription_receives_a_uuid_automatically(): void
    {
        $this->assertNotNull($this->makePrescription()->uuid);
    }

    public function test_status_is_cast_to_the_prescription_status_enum(): void
    {
        $this->assertSame(PrescriptionStatus::Active, $this->makePrescription()->status);
    }

    public function test_can_have_multiple_lines(): void
    {
        $prescription = $this->makePrescription();
        $prescription->lines()->create(['medication_name' => 'Paracétamol', 'dosage' => '1g', 'frequency' => '3x/jour']);
        $prescription->lines()->create(['medication_name' => 'Amoxicilline', 'dosage' => '500mg', 'frequency' => '2x/jour']);

        $this->assertSame(2, $prescription->lines()->count());
    }

    public function test_cancel_sets_status_and_is_audited_once_with_the_reason(): void
    {
        $prescription = $this->makePrescription();

        $prescription->cancel('Allergie découverte');

        $fresh = $prescription->fresh();
        $this->assertSame(PrescriptionStatus::Cancelled, $fresh->status);
        $this->assertSame('Allergie découverte', $fresh->cancel_reason);
        $this->assertNotNull($fresh->cancelled_at);

        $this->assertSame(1, AuditLog::where('action', 'cancel')
            ->where('entity_type', Prescription::class)
            ->count());
        $this->assertSame(0, AuditLog::where('action', 'update')
            ->where('entity_type', Prescription::class)
            ->count());

        $log = AuditLog::where('action', 'cancel')->first();
        $this->assertSame('Allergie découverte', $log->reason);
    }

    public function test_cancel_refuses_an_already_cancelled_prescription(): void
    {
        $prescription = $this->makePrescription();
        $prescription->cancel('Première annulation');

        $this->expectException(InvalidPrescriptionTransitionException::class);

        $prescription->cancel('Deuxième tentative');
    }
}
