<?php

namespace Tests\Feature\Patient;

use App\Services\Patient\PatientNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_number_is_prefixed_by_the_site_code_and_zero_padded(): void
    {
        config(['rivo.site.code' => 'M']);

        $number = (new PatientNumberGenerator)->next();

        $this->assertSame('M-000001', $number);
    }

    public function test_numbers_increment_sequentially(): void
    {
        config(['rivo.site.code' => 'A']);
        $generator = new PatientNumberGenerator;

        $this->assertSame('A-000001', $generator->next());
        $this->assertSame('A-000002', $generator->next());
        $this->assertSame('A-000003', $generator->next());
    }

    public function test_falls_back_to_a_placeholder_prefix_when_site_code_is_unset(): void
    {
        config(['rivo.site.code' => null]);

        $number = (new PatientNumberGenerator)->next();

        $this->assertSame('X-000001', $number);
    }
}
