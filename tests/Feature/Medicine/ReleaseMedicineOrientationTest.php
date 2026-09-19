<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\AuditLog;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\ConsultationDraft;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-127 — un patient pris en charge par erreur en Médecine retrouve sa place dans
 * la file, tant que la consultation ouverte est restée vierge.
 */
class ReleaseMedicineOrientationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_doctor_puts_the_patient_back_in_the_queue_at_their_place(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->takenPatient($doctor);
        $arrivedAt = $orientation->oriented_at;

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/release")
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('medicine.index'));

        $fresh = $orientation->fresh();
        $this->assertSame(EpisodeOrientationStatus::Pending, $fresh->status);
        $this->assertNull($fresh->accepted_by);
        $this->assertNull($fresh->accepted_at);
        $this->assertTrue($arrivedAt->equalTo($fresh->oriented_at));
        $this->assertSame(0, Consultation::withTrashed()->count());
        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $episode->fresh()->administrative_status);
        $this->assertNull($episode->fresh()->medical_status);
        $this->assertTrue(AuditLog::query()->where('action', 'medicine.orientation.release')->exists());
    }

    public function test_the_patient_can_then_be_taken_again_with_a_clean_consultation(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->takenPatient($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/release");
        $this->actingAs($this->doctor())->post("/medicine/orientations/{$orientation->uuid}/accept")->assertSessionHasNoErrors();

        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
        $this->assertSame(1, Consultation::query()->count());
    }

    public function test_the_unsent_draft_is_dropped_with_the_consultation(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->takenPatient($doctor);
        ConsultationDraft::query()->create([
            'episode_orientation_id' => $orientation->id,
            'created_by' => $doctor->id,
            'payload' => ['consultation' => ['reason' => 'x']],
        ]);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/release")->assertSessionHasNoErrors();

        $this->assertSame(0, ConsultationDraft::query()->count());
    }

    public function test_only_the_doctor_who_took_the_patient_can_put_them_back(): void
    {
        [, $orientation] = $this->takenPatient($this->doctor());

        $this->actingAs($this->doctor())->post("/medicine/orientations/{$orientation->uuid}/release")
            ->assertSessionHasErrors('orientation');

        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
    }

    public function test_a_consultation_the_doctor_has_written_in_is_never_given_back(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->takenPatient($doctor);
        Consultation::query()->where('episode_orientation_id', $orientation->id)->sole()
            ->update(['chief_complaint' => 'Toux depuis 3 jours']);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/release")
            ->assertSessionHasErrors('orientation');

        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
        $this->assertSame(1, Consultation::query()->count());
    }

    public function test_an_edited_initial_reason_counts_as_started(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->takenPatient($doctor);
        Consultation::query()->where('episode_orientation_id', $orientation->id)->sole()
            ->update(['reason' => 'Douleur thoracique']);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/release")
            ->assertSessionHasErrors('orientation');
    }

    public function test_a_pending_patient_cannot_be_released(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->takenPatient($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/release");

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/release")
            ->assertSessionHasErrors('orientation');
    }

    public function test_the_queue_exposes_who_took_the_patient(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->takenPatient($doctor);

        $this->actingAs($doctor)->get('/medicine?filter=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orientations.data.0.accepted_by_id', $doctor->id)
                ->where('orientations.data.0.status', 'IN_PROGRESS'));
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'MEDICINE']);

        foreach (['medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update', 'patients.view'] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function takenPatient(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $item->uuid,
            'quantity' => 1,
        ]], $doctor);
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
