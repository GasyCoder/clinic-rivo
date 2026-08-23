<?php

namespace Tests\Feature\Patient;

use App\Services\Patient\PatientNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_number_contains_the_site_year_and_zero_padded_sequence(): void
    {
        Carbon::setTestNow('2026-08-22 10:00:00');
        config(['rivo.site.code' => 'M']);

        $number = (new PatientNumberGenerator)->next();

        $this->assertSame('M-26-0001', $number);
    }

    public function test_numbers_increment_sequentially_within_the_same_year(): void
    {
        Carbon::setTestNow('2026-08-22 10:00:00');
        config(['rivo.site.code' => 'A']);
        $generator = new PatientNumberGenerator;

        $this->assertSame('A-26-0001', $generator->next());
        $this->assertSame('A-26-0002', $generator->next());
        $this->assertSame('A-26-0003', $generator->next());
    }

    public function test_sequence_restarts_for_a_new_calendar_year(): void
    {
        config(['rivo.site.code' => 'M']);
        $generator = new PatientNumberGenerator;

        Carbon::setTestNow('2026-12-31 23:59:00');
        $this->assertSame('M-26-0001', $generator->next());

        Carbon::setTestNow('2027-01-01 00:01:00');
        $this->assertSame('M-27-0001', $generator->next());
    }

    public function test_existing_annual_counter_is_never_reset(): void
    {
        Carbon::setTestNow('2026-08-22 10:00:00');
        config(['rivo.site.code' => 'M']);
        DB::table('patient_number_sequences')->insert([
            'year' => 2026,
            'next_number' => 42,
        ]);

        $this->assertSame('M-26-0042', (new PatientNumberGenerator)->next());
    }

    public function test_falls_back_to_a_placeholder_prefix_when_site_code_is_unset(): void
    {
        Carbon::setTestNow('2026-08-22 10:00:00');
        config(['rivo.site.code' => null]);

        $number = (new PatientNumberGenerator)->next();

        $this->assertSame('X-26-0001', $number);
    }
}
