<?php

namespace Tests\Feature\ClinicalFlow;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Care\CompleteCareAndOrientToMedicineAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Exceptions\InvalidEpisodeOrientationTransitionException;
use App\Models\AuditLog;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeOrientationFlowTest extends TestCase
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

    public function test_normal_arrival_enters_care_but_not_medicine(): void
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Care->value,
            'status' => EpisodeOrientationStatus::Pending->value,
        ]);
        $this->assertDatabaseMissing('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
        ]);
    }

    public function test_emergency_arrival_enters_care_and_medicine_immediately(): void
    {
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient(), EpisodePriority::Emergency);

        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $episode->administrative_status);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Care->value,
            'status' => EpisodeOrientationStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => EpisodeOrientationStatus::Pending->value,
        ]);
    }

    public function test_care_must_be_accepted_before_it_can_orient_a_normal_patient_to_medicine(): void
    {
        $actor = User::factory()->create();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $care = $episode->orientations->sole();

        $this->expectException(InvalidEpisodeOrientationTransitionException::class);

        $this->app->make(CompleteCareAndOrientToMedicineAction::class)
            ->execute($care, $actor);
    }

    public function test_completing_care_creates_the_medicine_queue_entry(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $care = $episode->orientations->sole();

        $this->app->make(AcceptCareOrientationAction::class)->execute($care, $actor);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $completed = $this->app->make(CompleteCareAndOrientToMedicineAction::class)
            ->execute($care, $actor);

        $this->assertSame(EpisodeOrientationStatus::Completed, $completed->status);
        $this->assertNull($completed->active_key);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => CatalogModule::Care->value,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => EpisodeOrientationStatus::Pending->value,
        ]);
        $this->assertGreaterThanOrEqual(3, AuditLog::query()
            ->where('entity_type', EpisodeOrientation::class)
            ->where('module', 'clinical_flow')
            ->count());
    }

    public function test_medicine_can_accept_only_a_medicine_orientation(): void
    {
        $doctor = User::factory()->create();
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient(), EpisodePriority::Emergency);
        $medicine = $episode->orientations->sole('destination_module', CatalogModule::Medicine);

        $accepted = $this->app->make(AcceptMedicineOrientationAction::class)
            ->execute($medicine, $doctor);

        $this->assertSame(EpisodeOrientationStatus::InProgress, $accepted->status);
        $this->assertSame($doctor->id, $accepted->accepted_by);
    }

    public function test_an_active_destination_is_idempotent_and_not_duplicated(): void
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $action = $this->app->make(CreateEpisodeOrientationAction::class);

        $first = $action->execute($episode, CatalogModule::Reception, CatalogModule::Care);
        $second = $action->execute($episode, CatalogModule::Reception, CatalogModule::Care);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $episode->orientations()->where('destination_module', CatalogModule::Care->value)->count());
    }
}
