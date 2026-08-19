<?php

namespace Tests\Feature\Patient;

use App\Actions\Patient\RecordPatientAllergyAction;
use App\Actions\Patient\RecordPatientAntecedentAction;
use App\Enums\AllergySeverity;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordPatientMedicalHistoryActionsTest extends TestCase
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

    public function test_record_antecedent_action_records_who_captured_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $patient = $this->makePatient();

        $antecedent = $this->app->make(RecordPatientAntecedentAction::class)
            ->execute($patient, 'Diabète type 2');

        $this->assertSame('Diabète type 2', $antecedent->description);
        $this->assertSame($user->id, $antecedent->recorded_by);
    }

    public function test_record_allergy_action_records_substance_reaction_and_severity(): void
    {
        $patient = $this->makePatient();

        $allergy = $this->app->make(RecordPatientAllergyAction::class)
            ->execute($patient, 'Pénicilline', 'Œdème de Quincke', AllergySeverity::Severe);

        $this->assertSame('Pénicilline', $allergy->substance);
        $this->assertSame('Œdème de Quincke', $allergy->reaction);
        $this->assertSame(AllergySeverity::Severe, $allergy->severity);
    }
}
