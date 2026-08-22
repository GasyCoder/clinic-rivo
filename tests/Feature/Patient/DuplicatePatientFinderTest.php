<?php

namespace Tests\Feature\Patient;

use App\Models\Patient;
use App\Services\Patient\DuplicatePatientFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicatePatientFinderTest extends TestCase
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

    public function test_finds_an_exact_match(): void
    {
        $patient = $this->makePatient();

        $matches = (new DuplicatePatientFinder)->find('Jean', 'Rakoto', '1990-05-12');

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($patient));
    }

    public function test_match_is_case_and_whitespace_insensitive(): void
    {
        $this->makePatient();

        $matches = (new DuplicatePatientFinder)->find('  JEAN  ', 'rakoto', '1990-05-12');

        $this->assertCount(1, $matches);
    }

    public function test_does_not_match_a_different_birth_date(): void
    {
        $this->makePatient();

        $matches = (new DuplicatePatientFinder)->find('Jean', 'Rakoto', '1991-05-12');

        $this->assertCount(0, $matches);
    }

    public function test_finds_a_match_from_the_same_declared_age_when_birth_is_unknown(): void
    {
        $patient = $this->makePatient([
            'birth_date' => null,
            'birth_date_is_approximate' => true,
            'declared_age' => 34,
            'declared_age_at' => now(),
        ]);

        $matches = (new DuplicatePatientFinder)->find('Jean', 'Rakoto', null, 34);

        $this->assertCount(1, $matches);
        $this->assertTrue($matches->first()->is($patient));
    }

    public function test_does_not_match_a_different_declared_age(): void
    {
        $this->makePatient([
            'birth_date' => null,
            'birth_date_is_approximate' => true,
            'declared_age' => 34,
            'declared_age_at' => now(),
        ]);

        $matches = (new DuplicatePatientFinder)->find('Jean', 'Rakoto', null, 35);

        $this->assertCount(0, $matches);
    }

    public function test_does_not_match_a_different_name(): void
    {
        $this->makePatient();

        $matches = (new DuplicatePatientFinder)->find('Marie', 'Rakoto', '1990-05-12');

        $this->assertCount(0, $matches);
    }

    public function test_does_not_match_a_soft_deleted_patient(): void
    {
        $patient = $this->makePatient();
        $patient->delete();

        $matches = (new DuplicatePatientFinder)->find('Jean', 'Rakoto', '1990-05-12');

        $this->assertCount(0, $matches);
    }
}
