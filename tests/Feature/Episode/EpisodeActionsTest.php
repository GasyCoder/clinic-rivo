<?php

namespace Tests\Feature\Episode;

use App\Actions\Episode\CancelEpisodeAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\OrientEpisodeAction;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeActionsTest extends TestCase
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

    public function test_create_episode_action_opens_an_episode_with_a_generated_number(): void
    {
        config(['rivo.site.code' => 'M']);
        $patient = $this->makePatient();

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);

        $this->assertInstanceOf(Episode::class, $episode);
        $this->assertSame('ME-000001', $episode->episode_number);
        $this->assertSame(EpisodeStatus::Open, $episode->status);
        $this->assertSame(EpisodePriority::Normal, $episode->priority);
        $this->assertSame(EpisodeAdministrativeStatus::PendingOrientation, $episode->administrative_status);
        $this->assertTrue($episode->patient->is($patient));
    }

    public function test_emergency_episode_is_marked_and_immediately_oriented(): void
    {
        $patient = $this->makePatient();

        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($patient, EpisodePriority::Emergency);

        $this->assertSame(EpisodePriority::Emergency, $episode->priority);
        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $episode->administrative_status);
    }

    public function test_orient_episode_action_moves_administrative_status_forward(): void
    {
        $patient = $this->makePatient();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);

        $oriented = $this->app->make(OrientEpisodeAction::class)->execute($episode);

        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $oriented->administrative_status);
    }

    public function test_cancel_episode_action_cancels_with_a_reason(): void
    {
        $patient = $this->makePatient();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);

        $cancelled = $this->app->make(CancelEpisodeAction::class)->execute($episode, 'Doublon');

        $this->assertSame(EpisodeStatus::Cancelled, $cancelled->status);
    }
}
