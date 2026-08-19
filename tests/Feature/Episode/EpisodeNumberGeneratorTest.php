<?php

namespace Tests\Feature\Episode;

use App\Services\Episode\EpisodeNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_number_is_prefixed_by_the_site_code_with_an_e_marker_and_zero_padded(): void
    {
        config(['rivo.site.code' => 'M']);

        $number = (new EpisodeNumberGenerator)->next();

        $this->assertSame('ME-000001', $number);
    }

    public function test_numbers_increment_sequentially(): void
    {
        config(['rivo.site.code' => 'A']);
        $generator = new EpisodeNumberGenerator;

        $this->assertSame('AE-000001', $generator->next());
        $this->assertSame('AE-000002', $generator->next());
        $this->assertSame('AE-000003', $generator->next());
    }

    public function test_falls_back_to_a_placeholder_prefix_when_site_code_is_unset(): void
    {
        config(['rivo.site.code' => null]);

        $number = (new EpisodeNumberGenerator)->next();

        $this->assertSame('XE-000001', $number);
    }

    public function test_episode_and_patient_sequences_are_independent(): void
    {
        config(['rivo.site.code' => 'M']);

        $episodeNumber = (new EpisodeNumberGenerator)->next();
        $patientNumber = (new \App\Services\Patient\PatientNumberGenerator)->next();

        $this->assertSame('ME-000001', $episodeNumber);
        $this->assertSame('M-000001', $patientNumber);
    }
}
