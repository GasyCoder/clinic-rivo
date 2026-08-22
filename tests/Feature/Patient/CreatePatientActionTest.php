<?php

namespace Tests\Feature\Patient;

use App\Actions\Patient\CreatePatientAction;
use App\Exceptions\DuplicatePatientException;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreatePatientActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function data(array $overrides = []): array
    {
        return [
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            ...$overrides,
        ];
    }

    public function test_creates_a_patient_with_a_generated_patient_number(): void
    {
        Carbon::setTestNow('2026-08-22 10:00:00');
        config(['rivo.site.code' => 'M']);

        $patient = $this->app->make(CreatePatientAction::class)->execute($this->data());

        $this->assertInstanceOf(Patient::class, $patient);
        $this->assertSame('M-26-0001', $patient->patient_number);
    }

    public function test_throws_when_a_duplicate_exists_and_is_not_confirmed(): void
    {
        $action = $this->app->make(CreatePatientAction::class);
        $action->execute($this->data());

        $this->expectException(DuplicatePatientException::class);

        $action->execute($this->data());
    }

    public function test_duplicate_exception_carries_the_matching_patients(): void
    {
        $action = $this->app->make(CreatePatientAction::class);
        $first = $action->execute($this->data());

        try {
            $action->execute($this->data());
            $this->fail('Expected DuplicatePatientException to be thrown.');
        } catch (DuplicatePatientException $e) {
            $this->assertCount(1, $e->matches);
            $this->assertTrue($e->matches->first()->is($first));
        }
    }

    public function test_creates_anyway_when_the_duplicate_is_confirmed(): void
    {
        $action = $this->app->make(CreatePatientAction::class);
        $action->execute($this->data());

        $second = $action->execute($this->data(), confirmDuplicate: true);

        $this->assertSame(2, Patient::count());
        $this->assertNotNull($second->id);
    }

    public function test_does_not_flag_a_different_patient_as_a_duplicate(): void
    {
        $action = $this->app->make(CreatePatientAction::class);
        $action->execute($this->data());

        $second = $action->execute($this->data(['first_name' => 'Marie']));

        $this->assertSame(2, Patient::count());
        $this->assertNotNull($second->id);
    }

    public function test_creates_a_patient_without_a_first_name(): void
    {
        $data = $this->data();
        unset($data['first_name']);

        $patient = $this->app->make(CreatePatientAction::class)->execute($data);

        $this->assertNull($patient->first_name);
    }

    public function test_keeps_a_declared_age_without_manufacturing_a_birth_date(): void
    {
        $data = $this->data();
        unset($data['birth_date']);
        $data['age'] = 34;

        $patient = $this->app->make(CreatePatientAction::class)->execute($data);

        $this->assertTrue($patient->birth_date_is_approximate);
        $this->assertNull($patient->birth_date);
        $this->assertSame(34, $patient->declared_age);
        $this->assertNotNull($patient->declared_age_at);
    }

    public function test_birth_date_given_directly_is_not_flagged_approximate(): void
    {
        $patient = $this->app->make(CreatePatientAction::class)->execute($this->data());

        $this->assertFalse($patient->birth_date_is_approximate);
        $this->assertNull($patient->declared_age);
        $this->assertNull($patient->declared_age_at);
    }

    public function test_two_patients_who_both_omit_first_name_still_match_as_duplicates(): void
    {
        $data = $this->data();
        unset($data['first_name']);
        $action = $this->app->make(CreatePatientAction::class);
        $action->execute($data);

        $this->expectException(DuplicatePatientException::class);

        $action->execute($data);
    }
}
