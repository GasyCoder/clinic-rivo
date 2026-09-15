<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One patient, one Soins handler, one hand-over to Médecine.
 */
class CareHandlerExclusivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_colleague_cannot_transfer_a_patient_someone_else_took_in_charge(): void
    {
        $handler = $this->nurse();
        $colleague = $this->nurse();
        [$episode, $care] = $this->careThenMedicineEpisode($handler);

        $this->actingAs($colleague)
            ->post("/care/orientations/{$care->uuid}/complete")
            ->assertSessionHasErrors('orientation');

        $this->assertSame(EpisodeOrientationStatus::InProgress, $care->fresh()->status);
        $this->assertSame(0, $this->medicineOrientations($episode));

        // Le collègue voit la fiche, signalée comme prise par quelqu'un
        // d'autre, et ne peut pas la terminer. Depuis la décision du
        // 2026-09-15 il peut en revanche la corriger : une erreur de saisie
        // ne doit pas attendre le retour de service du soignant. Seul le
        // transfert reste exclusif.
        $this->actingAs($colleague)->get(route('care.orientations.show', $care))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_edit', true)
                ->where('capabilities.can_complete', false)
                ->where('capabilities.handled_by_other', true));
    }

    public function test_a_patient_is_handed_to_medicine_only_once(): void
    {
        $handler = $this->nurse();
        [$episode, $care] = $this->careThenMedicineEpisode($handler);

        $this->actingAs($handler)
            ->post("/care/orientations/{$care->uuid}/complete")
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->medicineOrientations($episode));

        // A second click, a stale tab or another workstation: refused with a
        // readable reason instead of a server error, and nothing is created.
        $this->actingAs($handler)
            ->post("/care/orientations/{$care->uuid}/complete")
            ->assertSessionHasErrors('orientation');
        $this->actingAs($this->nurse())
            ->post("/care/orientations/{$care->uuid}/accept")
            ->assertSessionHasErrors('orientation');

        $this->assertSame(1, $this->medicineOrientations($episode));
    }

    public function test_a_reopened_care_queue_never_sends_the_patient_to_medicine_again(): void
    {
        $handler = $this->nurse();
        $doctor = $this->nurse();
        [$episode, $care] = $this->careThenMedicineEpisode($handler);

        $this->actingAs($handler)->post("/care/orientations/{$care->uuid}/complete");

        // Médecine sees the patient and finishes, then the visit reappears in
        // the Soins queue (e.g. requalified as an emergency).
        $medicine = $episode->orientations()->where('destination_module', CatalogModule::Medicine->value)->sole();
        $medicine->accept($doctor);
        $medicine->complete($doctor);
        $second = $this->app->make(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Reception, CatalogModule::Care, $handler, 'Réouverture');
        $colleague = $this->nurse();
        $this->app->make(AcceptCareOrientationAction::class)->execute($second, $colleague);

        $this->actingAs($colleague)
            ->post("/care/orientations/{$second->uuid}/complete")
            ->assertSessionHasNoErrors();

        $this->assertSame(EpisodeOrientationStatus::Completed, $second->fresh()->status);
        $this->assertSame(1, $this->medicineOrientations($episode));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function medicineOrientations(Episode $episode): int
    {
        return EpisodeOrientation::query()
            ->where('episode_id', $episode->id)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->count();
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function careThenMedicineEpisode(User $nurse): array
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute(Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]));
        $consultation = CatalogItem::query()->firstOrCreate(
            ['code' => 'CONSULT-GEN-EXCL'],
            [
                'name' => 'Consultation générale',
                'type' => CatalogItemType::Service,
                'module' => CatalogModule::Medicine,
                'unit' => 'consultation',
                'billable' => true,
                'stockable' => false,
                'reception_selectable' => true,
                'reception_routing_mode' => ReceptionRoutingMode::CareThenMedicine,
                'created_by' => $nurse->id,
                'updated_by' => $nurse->id,
            ],
        );
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $consultation->uuid,
            'quantity' => 1,
        ]], $nurse);
        $care = $episode->orientations()->sole();
        $this->app->make(AcceptCareOrientationAction::class)->execute($care, $nurse);

        return [$episode, $care->fresh()];
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
