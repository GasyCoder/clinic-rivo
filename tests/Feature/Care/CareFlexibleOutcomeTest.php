<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\BillableItem;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-166 — la suite des Soins se choisit à l'étape Terminer : un patient
 * attendu en Médecine peut être terminé aux Soins (avec un motif), un patient
 * venu pour un soin peut être envoyé au médecin.
 */
class CareFlexibleOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_patient_expected_in_medicine_is_finished_at_care_only_with_a_reason(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareThenMedicine);
        $billedBefore = BillableItem::query()->where('episode_id', $orientation->episode_id)->get(['id', 'status'])->toArray();

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record-and-complete", [
                'temperature_celsius' => 37.2,
                'care_outcome' => 'FINISH',
            ])
            ->assertSessionHasErrors('care_outcome_reason');

        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
        $this->assertFalse($this->hasMedicineOrientation($orientation->episode_id));

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record-and-complete", [
                'temperature_celsius' => 37.2,
                'care_outcome' => 'FINISH',
                'care_outcome_reason' => '  Besoin couvert par le soin  ',
            ])
            ->assertRedirect(route('care.index'));

        $completed = $orientation->fresh();
        $this->assertSame('COMPLETED', $completed->status->value);
        $this->assertSame('Besoin couvert par le soin', $completed->completion_reason);
        $this->assertFalse($this->hasMedicineOrientation($orientation->episode_id));
        // Plus aucun service n'a le patient : il rejoint « Sorties & règlements ».
        $this->assertSame('PENDING_SETTLEMENT', Episode::find($orientation->episode_id)->administrative_status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'care.orientation.finish_at_care']);
        // Décision du propriétaire : la consultation prévue reste facturée.
        $this->assertSame(
            $billedBefore,
            BillableItem::query()->where('episode_id', $orientation->episode_id)->get(['id', 'status'])->toArray(),
        );

        // La Réception lit le motif dans le parcours du passage.
        $this->actingAs($nurse)
            ->get('/passages/'.Episode::find($orientation->episode_id)->uuid)
            ->assertInertia(fn (Assert $page) => $page->where('episode.pathway', fn ($steps) => collect($steps)->contains(
                fn ($step) => collect(collect($step)->get('notes', []))->contains(
                    'Terminé aux Soins, sans passer en Médecine — motif : Besoin couvert par le soin',
                ),
            )));
    }

    public function test_finishing_without_new_entry_writes_no_care_record(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareThenMedicine);

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/complete", [
                'care_outcome' => 'FINISH',
                'care_outcome_reason' => 'Patient reparti avant la consultation',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('COMPLETED', $orientation->fresh()->status->value);
        $this->assertSame('Patient reparti avant la consultation', $orientation->fresh()->completion_reason);
        $this->assertFalse(CareRecord::query()->where('episode_id', $orientation->episode_id)->exists());
    }

    public function test_a_care_only_patient_can_be_sent_to_the_doctor_only_with_a_reason(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareOnly);

        // Toute suite non prévue exige un motif, dans les deux sens (amendement du 2026-09-21).
        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record-and-complete", [
                'care_outcome' => 'MEDICINE',
                'transmission_reason' => '<p>Douleur thoracique signalée pendant le soin</p>',
            ])
            ->assertSessionHasErrors('care_outcome_reason');
        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
        $this->assertFalse($this->hasMedicineOrientation($orientation->episode_id));

        $this->actingAs($nurse)
            ->put("/care/orientations/{$orientation->uuid}/record-and-complete", [
                'care_outcome' => 'MEDICINE',
                'care_outcome_reason' => 'Douleur thoracique pendant l’injection',
                'transmission_reason' => '<p>Douleur thoracique signalée pendant le soin</p>',
            ])
            ->assertRedirect(route('care.index'));

        $this->assertSame('COMPLETED', $orientation->fresh()->status->value);
        $this->assertSame('Douleur thoracique pendant l’injection', $orientation->fresh()->completion_reason);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $orientation->episode_id,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => 'PENDING',
            'reason' => 'Orientation vers Médecine décidée aux Soins, hors du parcours prévu — motif : Douleur thoracique pendant l’injection',
        ]);
        $this->assertStringContainsString(
            'Douleur thoracique',
            (string) CareRecord::query()->where('episode_id', $orientation->episode_id)->value('transmission_reason'),
        );
        $this->assertSame('IN_CARE', Episode::find($orientation->episode_id)->administrative_status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'care.orientation.send_to_medicine']);

        // Le parcours du passage dit dans quel sens la suite a changé.
        $this->actingAs($nurse)
            ->get('/passages/'.Episode::find($orientation->episode_id)->uuid)
            ->assertInertia(fn (Assert $page) => $page->where('episode.pathway', fn ($steps) => collect($steps)->contains(
                fn ($step) => collect(collect($step)->get('notes', []))->contains(
                    'Transmis au médecin, hors du parcours prévu — motif : Douleur thoracique pendant l’injection',
                ),
            )));

        $this->actingAs($nurse)
            ->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('completionOutcome', 'MEDICINE'));
    }

    public function test_a_care_only_patient_finished_at_care_still_needs_an_act(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareOnly);

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/complete", ['care_outcome' => 'FINISH'])
            ->assertSessionHasErrors('procedures');

        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
    }

    public function test_when_medicine_already_has_the_patient_finishing_needs_no_reason_and_cancels_nothing(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareThenMedicine);
        $medicine = $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $orientation->episode,
            CatalogModule::Reception,
            CatalogModule::Medicine,
            $nurse,
            'Urgence : Médecine ouverte en parallèle.',
        );

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/complete", ['care_outcome' => 'FINISH'])
            ->assertSessionHasNoErrors();

        $this->assertSame('COMPLETED', $orientation->fresh()->status->value);
        $this->assertNull($orientation->fresh()->completion_reason);
        $this->assertSame('PENDING', $medicine->fresh()->status->value);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'care.orientation.finish_at_care']);
    }

    public function test_the_page_tells_whether_medicine_already_has_the_patient(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareThenMedicine);

        $this->actingAs($nurse)
            ->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('medicineAlreadyInvolved', false)
                ->where('completionReason', null));

        $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $orientation->episode,
            CatalogModule::Reception,
            CatalogModule::Medicine,
            $nurse,
            'Urgence : Médecine ouverte en parallèle.',
        );

        $this->actingAs($nurse)
            ->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('medicineAlreadyInvolved', true));
    }

    public function test_only_medicine_or_finish_are_accepted_as_an_outcome(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareThenMedicine);

        $this->actingAs($nurse)
            ->post("/care/orientations/{$orientation->uuid}/complete", ['care_outcome' => 'CHOICE'])
            ->assertSessionHasErrors('care_outcome');

        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
    }

    public function test_the_chosen_outcome_survives_a_reload_in_the_draft(): void
    {
        $nurse = $this->nurse();
        $orientation = $this->activeCareOrientation($nurse, ReceptionRoutingMode::CareThenMedicine);

        $this->actingAs($nurse)
            ->putJson("/care/orientations/{$orientation->uuid}/draft", [
                'payload' => ['care_outcome' => 'FINISH', 'care_outcome_reason' => 'Besoin couvert'],
            ])
            ->assertOk();

        $this->actingAs($nurse)
            ->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('careRecordDraft.payload.care_outcome', 'FINISH')
                ->where('careRecordDraft.payload.care_outcome_reason', 'Besoin couvert'));
    }

    private function hasMedicineOrientation(int $episodeId): bool
    {
        return EpisodeOrientation::query()
            ->where('episode_id', $episodeId)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->exists();
    }

    private function activeCareOrientation(User $nurse, ReceptionRoutingMode $routingMode): EpisodeOrientation
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute(Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]));
        $service = CatalogItem::query()->create([
            'code' => 'CARE-'.$routingMode->value,
            'name' => 'Soin '.$routingMode->value,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => $routingMode,
            'created_by' => $nurse->id,
            'updated_by' => $nurse->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
        ]], $nurse);
        $orientation = $episode->orientations()->sole();
        $this->app->make(AcceptCareOrientationAction::class)->execute($orientation, $nurse);

        return $orientation->fresh();
    }

    private function nurse(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'NURSE'], ['name' => 'NURSE']);

        foreach (['patients.view', 'care.view', 'care.create', 'care.update', 'care.complete', 'vitals.view', 'vitals.create', 'vitals.update'] as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
