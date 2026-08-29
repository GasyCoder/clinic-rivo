<?php

namespace Tests\Feature\Episode;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Models\AuditLog;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ReceptionJourneyDraft;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeEmergencyFlowTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => 'A-26-9001',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1992-05-12',
            'sex' => 'F',
        ]);
    }

    public function test_reception_marks_only_the_selected_episode_and_keeps_its_draft(): void
    {
        $receptionist = $this->actor('RECEPTION', [
            'episodes.update', 'episodes.mark_emergency', 'patients.view',
        ]);
        $this->actingAs($receptionist);

        $patient = $this->patient();
        $selected = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $other = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        ReceptionJourneyDraft::query()->create([
            'episode_id' => $selected->id,
            'catalog_lines' => [],
            'designation_deferred' => true,
            'created_by' => $receptionist->id,
        ]);

        $this->get(route('reception.passages.journey.show', $selected))
            ->assertInertia(fn ($page) => $page
                ->where('resumeEpisode.uuid', $selected->uuid)
                ->where('capabilities.can_mark_emergency', true));

        $this->post(route('reception.passages.emergency.store', $selected))
            ->assertRedirect(route('patients.show', $patient));

        $this->assertSame(EpisodePriority::Emergency, $selected->fresh()->priority);
        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $selected->fresh()->administrative_status);
        $this->assertSame(EpisodePriority::Normal, $other->fresh()->priority);
        $this->assertDatabaseHas('reception_journey_drafts', ['episode_id' => $selected->id]);
        $this->assertSame(
            [CatalogModule::Care, CatalogModule::Medicine],
            $selected->orientations()->orderBy('destination_module')->pluck('destination_module')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'episode.mark_emergency',
            'entity_type' => $selected->getMorphClass(),
            'entity_id' => $selected->id,
            'user_id' => $receptionist->id,
        ]);
    }

    public function test_reclassifying_an_emergency_is_idempotent(): void
    {
        $receptionist = $this->actor('RECEPTION', [
            'episodes.mark_emergency', 'patients.view',
        ]);
        $this->actingAs($receptionist);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());

        $this->post(route('reception.passages.emergency.store', $episode))->assertRedirect();
        $this->post(route('reception.passages.emergency.store', $episode))->assertRedirect();

        $this->assertSame(2, $episode->orientations()->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'episode.mark_emergency')
            ->where('entity_id', $episode->id)
            ->count());
    }

    public function test_medicine_reclassification_keeps_the_active_consultation_and_opens_care(): void
    {
        $doctor = $this->actor('MEDICINE', [
            'episodes.mark_emergency', 'consultations.view', 'consultations.create',
        ]);
        $this->actingAs($doctor);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $medicineOrientation = $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $episode,
            CatalogModule::Reception,
            CatalogModule::Medicine,
            $doctor,
        );
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($medicineOrientation, $doctor);
        $consultationId = $medicineOrientation->consultation()->sole()->id;

        $this->get(route('medicine.orientations.step', [$medicineOrientation, 'dossier']))
            ->assertInertia(fn ($page) => $page
                ->where('orientation.episode.priority', EpisodePriority::Normal->value)
                ->where('capabilities.can_mark_emergency', true));

        $this->from(route('medicine.orientations.step', [$medicineOrientation, 'dossier']))
            ->post(route('medicine.orientations.emergency.store', $medicineOrientation))
            ->assertRedirect(route('medicine.orientations.step', [$medicineOrientation, 'dossier']));

        $this->assertSame(EpisodePriority::Emergency, $episode->fresh()->priority);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $medicineOrientation->fresh()->status);
        $this->assertSame($consultationId, $medicineOrientation->consultation()->sole()->id);
        $this->assertSame(1, $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->count());
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Care->value,
            'status' => EpisodeOrientationStatus::Pending->value,
        ]);
    }

    public function test_marking_emergency_requires_the_granular_permission(): void
    {
        $user = $this->actor('MEDICINE', ['episodes.view']);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());

        $this->actingAs($user)
            ->post(route('reception.passages.emergency.store', $episode))
            ->assertForbidden();

        $this->assertSame(EpisodePriority::Normal, $episode->fresh()->priority);
        $this->assertSame(0, EpisodeOrientation::query()->count());
    }

    public function test_medicine_cannot_mark_emergency_outside_an_active_consultation(): void
    {
        $doctor = $this->actor('MEDICINE', ['episodes.mark_emergency']);
        $this->actingAs($doctor);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $pendingOrientation = $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $episode,
            CatalogModule::Reception,
            CatalogModule::Medicine,
            $doctor,
        );

        $this->from('/medicine')
            ->post(route('medicine.orientations.emergency.store', $pendingOrientation))
            ->assertRedirect('/medicine')
            ->assertSessionHasErrors('episode');

        $this->assertSame(EpisodePriority::Normal, $episode->fresh()->priority);
        $this->assertSame(1, $episode->orientations()->count());
    }
}
