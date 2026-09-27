<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Models\AuditLog;
use App\Models\CareRecord;
use App\Models\CareRecordDraft;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-122 — un patient pris en charge par erreur retrouve sa place dans la file,
 * tant qu'aucun soin n'a été enregistré.
 */
class ReleaseCareOrientationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_handler_puts_the_patient_back_in_the_queue_at_their_place(): void
    {
        $nurse = $this->nurse();
        $care = $this->takenPatient($nurse);
        $arrivedAt = $care->oriented_at;

        $this->actingAs($nurse)->post("/care/orientations/{$care->uuid}/release")
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('care.index'));

        $fresh = $care->fresh();
        $this->assertSame(EpisodeOrientationStatus::Pending, $fresh->status);
        $this->assertNull($fresh->accepted_by);
        $this->assertNull($fresh->accepted_at);
        // La place vient de l'heure d'orientation, que la prise en charge n'a jamais touchée.
        $this->assertTrue($arrivedAt->equalTo($fresh->oriented_at));
        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $fresh->episode->fresh()->administrative_status);
    }

    public function test_the_patient_can_then_be_taken_again(): void
    {
        $nurse = $this->nurse();
        $care = $this->takenPatient($nurse);

        $this->actingAs($nurse)->post("/care/orientations/{$care->uuid}/release");
        $this->actingAs($this->nurse())->post("/care/orientations/{$care->uuid}/accept")->assertSessionHasNoErrors();

        $this->assertSame(EpisodeOrientationStatus::InProgress, $care->fresh()->status);
    }

    public function test_only_the_person_who_took_the_patient_can_put_them_back(): void
    {
        $care = $this->takenPatient($this->nurse());

        $this->actingAs($this->nurse())->post("/care/orientations/{$care->uuid}/release")
            ->assertSessionHasErrors('orientation');

        $this->assertSame(EpisodeOrientationStatus::InProgress, $care->fresh()->status);
    }

    public function test_it_is_refused_as_soon_as_care_has_been_recorded(): void
    {
        $nurse = $this->nurse();
        $care = $this->takenPatient($nurse);
        CareRecord::query()->create([
            'episode_id' => $care->episode_id,
            'created_by' => $nurse->id,
            'updated_by' => $nurse->id,
        ]);

        $this->actingAs($nurse)->post("/care/orientations/{$care->uuid}/release")
            ->assertSessionHasErrors('orientation');

        $this->assertSame(EpisodeOrientationStatus::InProgress, $care->fresh()->status);
    }

    public function test_a_waiting_or_finished_patient_cannot_be_put_back(): void
    {
        $nurse = $this->nurse();
        $care = $this->takenPatient($nurse);
        $waiting = $this->takenPatient($nurse, accept: false);
        $care->fresh()->complete($nurse);

        $this->actingAs($nurse)->post("/care/orientations/{$waiting->uuid}/release")->assertSessionHasErrors('orientation');
        $this->actingAs($nurse)->post("/care/orientations/{$care->uuid}/release")->assertSessionHasErrors('orientation');
    }

    public function test_an_unsaved_draft_is_discarded_with_the_release(): void
    {
        $nurse = $this->nurse();
        $care = $this->takenPatient($nurse);
        CareRecordDraft::query()->create([
            'episode_orientation_id' => $care->id,
            'created_by' => $nurse->id,
            'payload' => ['heart_rate' => '7'],
        ]);

        $this->actingAs($nurse)->post("/care/orientations/{$care->uuid}/release")->assertSessionHasNoErrors();

        $this->assertSame(0, CareRecordDraft::query()->where('episode_orientation_id', $care->id)->count());
    }

    public function test_the_release_is_audited(): void
    {
        $nurse = $this->nurse();
        $care = $this->takenPatient($nurse);

        $this->actingAs($nurse)->post("/care/orientations/{$care->uuid}/release");

        $log = AuditLog::query()->where('action', 'care.orientation.release')->sole();
        $this->assertSame($nurse->id, $log->user_id);
        $this->assertSame('IN_PROGRESS', $log->old_values['status']);
        $this->assertSame('PENDING', $log->new_values['status']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function takenPatient(User $nurse, bool $accept = true): EpisodeOrientation
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute(Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]));
        $care = $this->app->make(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Reception, CatalogModule::Care, $nurse, 'Test');

        if ($accept) {
            $this->app->make(AcceptCareOrientationAction::class)->execute($care, $nurse);
        }

        return $care->fresh();
    }

    private function nurse(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'NURSE'], ['name' => 'NURSE']);

        foreach (['care.view', 'care.create', 'care.update', 'care.complete', 'vitals.view', 'vitals.create', 'vitals.update'] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
