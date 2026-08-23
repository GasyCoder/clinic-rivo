<?php

namespace Tests\Feature\Episode;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\Patient;
use App\Services\Episode\EpisodeNumberGenerator;
use App\Services\Patient\PatientNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EpisodeNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function patient(string $number = 'M-26-0001', string $lastName = 'Rakoto'): Patient
    {
        return Patient::create([
            'patient_number' => $number,
            'first_name' => 'Jean',
            'last_name' => $lastName,
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    public function test_first_passage_appends_the_patient_ordinal(): void
    {
        $patient = $this->patient();

        $number = (new EpisodeNumberGenerator)->next($patient);

        $this->assertSame('M-26-0001-01', $number);
    }

    public function test_passage_numbers_increment_independently_for_each_patient(): void
    {
        $generator = new EpisodeNumberGenerator;
        $first = $this->patient('M-26-0001');
        $second = $this->patient('M-26-0002', 'Rasoa');

        $this->assertSame('M-26-0001-01', $generator->next($first));
        $this->assertSame('M-26-0001-02', $generator->next($first));
        $this->assertSame('M-26-0002-01', $generator->next($second));
    }

    public function test_historical_episodes_with_null_sequence_are_counted(): void
    {
        $patient = $this->patient('M-000001');

        foreach ([1, 2] as $index) {
            Episode::create([
                'patient_id' => $patient->id,
                'visit_sequence' => null,
                'episode_number' => "MP-00000{$index}",
                'status' => EpisodeStatus::Open,
                'administrative_status' => EpisodeAdministrativeStatus::PendingOrientation,
                'started_at' => now()->addMinutes($index),
            ]);
        }

        $this->assertSame('M-000001-03', (new EpisodeNumberGenerator)->next($patient));
    }

    public function test_sequence_can_be_extracted_for_episode_persistence(): void
    {
        $patient = $this->patient();
        $generator = new EpisodeNumberGenerator;
        $number = $generator->next($patient);

        $this->assertSame(1, $generator->sequenceFromNumber($patient, $number));
    }

    public function test_episode_and_patient_sequences_are_independent(): void
    {
        Carbon::setTestNow('2026-08-22 10:00:00');
        config(['rivo.site.code' => 'M']);
        $patientNumber = (new PatientNumberGenerator)->next();
        $patient = $this->patient($patientNumber);
        $episodeNumber = (new EpisodeNumberGenerator)->next($patient);

        $this->assertSame('M-26-0001', $patientNumber);
        $this->assertSame('M-26-0001-01', $episodeNumber);
    }
}
