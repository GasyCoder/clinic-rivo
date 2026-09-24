<?php

namespace Tests\Feature\Care;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
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
 * ADR-167 — reprendre un patient pris en charge par un collègue : motif
 * obligatoire, tracé, et la suite des soins passe au compte qui reprend.
 */
class CareTakeOverTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_colleague_takes_over_with_a_reason_and_then_decides_the_outcome(): void
    {
        $first = $this->careAccount();
        $second = $this->careAccount();
        $orientation = $this->activeCareOrientation($first);
        $acceptedAt = $orientation->accepted_at->toIso8601String();

        $this->actingAs($second)
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => '  Fin de garde  '])
            ->assertRedirect(route('care.orientations.show', $orientation));

        $taken = $orientation->fresh();
        $this->assertSame($second->id, $taken->accepted_by);
        $this->assertSame($first->id, $taken->taken_over_from);
        $this->assertSame('Fin de garde', $taken->takeover_reason);
        $this->assertNotNull($taken->taken_over_at);
        // Le début réel des soins ne bouge pas.
        $this->assertSame($acceptedAt, $taken->accepted_at->toIso8601String());
        $this->assertDatabaseHas('audit_logs', ['action' => 'care.orientation.take_over']);

        // L'ancien soignant ne décide plus la suite…
        $this->actingAs($first)
            ->post("/care/orientations/{$orientation->uuid}/complete", ['care_outcome' => 'MEDICINE'])
            ->assertSessionHasErrors('orientation');
        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);

        // … celui qui a repris, si.
        $this->actingAs($second)
            ->post("/care/orientations/{$orientation->uuid}/complete", ['care_outcome' => 'MEDICINE'])
            ->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', $orientation->fresh()->status->value);
    }

    public function test_the_reason_is_required(): void
    {
        $orientation = $this->activeCareOrientation($this->careAccount());

        $this->actingAs($this->careAccount())
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertNull($orientation->fresh()->taken_over_at);
    }

    public function test_the_handler_cannot_take_over_their_own_patient(): void
    {
        $handler = $this->careAccount();
        $orientation = $this->activeCareOrientation($handler);

        $this->actingAs($handler)
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => 'Test'])
            ->assertSessionHasErrors('reason');

        $this->assertNull($orientation->fresh()->taken_over_at);
    }

    public function test_taking_over_requires_the_right_to_finish_care(): void
    {
        $orientation = $this->activeCareOrientation($this->careAccount());

        $this->actingAs($this->careAccount(withComplete: false))
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => 'Fin de garde'])
            ->assertForbidden();

        $this->assertNull($orientation->fresh()->taken_over_at);
    }

    public function test_only_a_care_in_progress_on_an_open_passage_can_be_taken_over(): void
    {
        $handler = $this->careAccount();
        $colleague = $this->careAccount();
        $orientation = $this->activeCareOrientation($handler);

        Episode::whereKey($orientation->episode_id)->update(['status' => 'CLOSED']);
        $this->actingAs($colleague)
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => 'Fin de garde'])
            ->assertSessionHasErrors('reason');

        Episode::whereKey($orientation->episode_id)->update(['status' => 'OPEN']);
        EpisodeOrientation::whereKey($orientation->id)->update(['status' => 'COMPLETED', 'active_key' => null]);
        $this->actingAs($colleague)
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => 'Fin de garde'])
            ->assertSessionHasErrors('reason');

        $this->assertSame($handler->id, $orientation->fresh()->accepted_by);
    }

    public function test_work_recorded_before_the_takeover_still_forbids_sending_the_patient_back_to_the_queue(): void
    {
        $first = $this->careAccount();
        $second = $this->careAccount();
        $orientation = $this->activeCareOrientation($first);

        $this->actingAs($first)
            ->put("/care/orientations/{$orientation->uuid}/record", ['temperature_celsius' => 37.1])
            ->assertSessionHasNoErrors();
        $this->assertTrue(CareRecord::query()->where('episode_id', $orientation->episode_id)->exists());

        $this->travel(5)->minutes();
        $this->actingAs($second)
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => 'Fin de garde']);

        $this->actingAs($second)
            ->post("/care/orientations/{$orientation->uuid}/release")
            ->assertSessionHasErrors('orientation');
        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
    }

    public function test_the_page_offers_the_takeover_to_a_colleague_and_says_who_decides(): void
    {
        $first = $this->careAccount();
        $second = $this->careAccount();
        $orientation = $this->activeCareOrientation($first);

        $this->actingAs($second)
            ->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.handled_by_other', true)
                ->where('capabilities.can_take_over', true)
                ->where('capabilities.can_complete', false)
                ->where('takeover', null));

        $this->actingAs($first)
            ->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('capabilities.can_take_over', false));

        $this->actingAs($second)
            ->post("/care/orientations/{$orientation->uuid}/take-over", ['reason' => 'Fin de garde']);

        $this->actingAs($second)
            ->get("/care/orientations/{$orientation->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.can_complete', true)
                ->where('capabilities.can_take_over', false)
                ->where('takeover.from', $first->name)
                ->where('takeover.reason', 'Fin de garde'));
    }

    private function activeCareOrientation(User $handler): EpisodeOrientation
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute(Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]));
        $service = CatalogItem::query()->create([
            'code' => 'CARE-'.fake()->unique()->numerify('####'),
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::CareThenMedicine,
            'created_by' => $handler->id,
            'updated_by' => $handler->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
        ]], $handler);
        // ADR-177 — une prestation d'arrivée n'ouvre plus de file : l'orientation
        // vers ce service est désormais un geste réel, posé ici explicitement.
        $this->app->make(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Care, $handler);
        $orientation = $episode->orientations()->sole();
        $this->app->make(AcceptCareOrientationAction::class)->execute($orientation, $handler);

        return $orientation->fresh();
    }

    private function careAccount(bool $withComplete = true): User
    {
        $code = $withComplete ? 'NURSE' : 'NURSE_NO_COMPLETE';
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);
        $names = ['patients.view', 'care.view', 'care.create', 'care.update', 'vitals.view', 'vitals.create', 'vitals.update'];

        if ($withComplete) {
            $names[] = 'care.complete';
        }

        foreach ($names as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
