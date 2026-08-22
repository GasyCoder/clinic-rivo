<?php

namespace Tests\Feature\Patient;

use App\Actions\Patient\UpdatePatientAction;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePatientActionTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): Patient
    {
        return Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    public function test_it_updates_the_administrative_record_from_an_exact_birth_date(): void
    {
        $patient = $this->app->make(UpdatePatientAction::class)->execute($this->patient(), [
            'first_name' => 'Jeanne',
            'last_name' => 'Rakoto',
            'birth_date' => '1992-06-20',
            'sex' => 'F',
            'phone' => '0341234567',
        ]);

        $this->assertSame('Jeanne', $patient->first_name);
        $this->assertSame('1992-06-20', $patient->birth_date->toDateString());
        $this->assertFalse($patient->birth_date_is_approximate);
        $this->assertSame('0341234567', $patient->phone);
    }

    public function test_it_records_a_declared_age_without_inventing_a_birth_date(): void
    {
        $patient = $this->app->make(UpdatePatientAction::class)->execute($this->patient(), [
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'age' => 48,
            'sex' => 'M',
        ]);

        $this->assertTrue($patient->birth_date_is_approximate);
        $this->assertNull($patient->birth_date);
        $this->assertSame(48, $patient->declared_age);
        $this->assertNotNull($patient->declared_age_at);
    }
}
