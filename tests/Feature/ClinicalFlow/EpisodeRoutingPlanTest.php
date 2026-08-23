<?php

namespace Tests\Feature\ClinicalFlow;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Care\CompleteCareAndOrientToMedicineAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EpisodeRoutingPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.code' => 'M']);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
    }

    public function test_a_direct_medicine_designation_opens_only_medicine_and_snapshots_the_route(): void
    {
        $ecg = $this->service('ECG', ReceptionRoutingMode::MedicineDirect);
        $episode = $this->episode();

        $requests = $this->plan($episode, [[$ecg, 2]]);

        $this->assertCount(1, $requests);
        $this->assertSame(ReceptionRoutingMode::MedicineDirect, $requests->first()->routing_mode);
        $this->assertSame('2.00', $requests->first()->quantity);
        $this->assertNotNull($episode->fresh()->service_plan_finalized_at);
        $this->assertFalse($episode->fresh()->designation_deferred);
        $this->assertDatabaseMissing('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Care->value,
        ]);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => 'PENDING',
        ]);

        $ecg->update(['reception_routing_mode' => ReceptionRoutingMode::CareOnly]);
        $this->assertSame(
            ReceptionRoutingMode::MedicineDirect,
            $episode->serviceRequests()->sole()->routing_mode,
        );
    }

    public function test_care_then_medicine_waits_for_completed_care(): void
    {
        $consultation = $this->service('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine);
        $episode = $this->episode();
        $this->plan($episode, [[$consultation, 1]]);

        $care = $episode->orientations()->sole();
        $this->assertSame(CatalogModule::Care, $care->destination_module);
        $this->assertDatabaseMissing('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
        ]);

        app(AcceptCareOrientationAction::class)->execute($care, $this->actor);
        app(CompleteCareAndOrientToMedicineAction::class)->execute($care, $this->actor);

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => CatalogModule::Care->value,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => 'PENDING',
        ]);
    }

    public function test_care_only_finishes_without_creating_medicine(): void
    {
        $injection = $this->service('INJECTION-IM', ReceptionRoutingMode::CareOnly);
        $injection->update(['care_requires_allergy_check' => true]);
        $episode = $this->episode();
        $this->plan($episode, [[$injection, 1]]);
        $care = $episode->orientations()->sole();

        $this->assertTrue($episode->serviceRequests()->sole()->care_requires_allergy_check);

        $injection->update(['care_requires_allergy_check' => false]);
        $this->assertTrue($episode->serviceRequests()->sole()->care_requires_allergy_check);

        app(AcceptCareOrientationAction::class)->execute($care, $this->actor);
        $this->recordPerformedProcedure($episode, $injection);
        app(CompleteCareAndOrientToMedicineAction::class)->execute($care, $this->actor);

        $this->assertDatabaseMissing('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
        ]);
    }

    public function test_a_mixed_plan_with_care_starts_in_care_then_hands_off_to_medicine(): void
    {
        $injection = $this->service('INJECTION-IM', ReceptionRoutingMode::CareOnly);
        $ecg = $this->service('ECG', ReceptionRoutingMode::MedicineDirect);
        $episode = $this->episode();
        $this->plan($episode, [[$injection, 1], [$ecg, 1]]);

        $this->assertSame(
            [CatalogModule::Care],
            $episode->orientations()->pluck('destination_module')->all(),
        );

        $care = $episode->orientations()->sole();
        app(AcceptCareOrientationAction::class)->execute($care, $this->actor);
        app(CompleteCareAndOrientToMedicineAction::class)->execute($care, $this->actor);

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => 'PENDING',
        ]);
    }

    public function test_unknown_need_is_durable_idempotent_and_requires_an_explicit_medicine_choice(): void
    {
        $episode = $this->episode();
        $planner = app(PlanEpisodeRoutingAction::class);

        $first = $planner->planUnknownNeed($episode, $this->actor);
        $second = $planner->planUnknownNeed($episode, $this->actor);

        $this->assertTrue($first->designation_deferred);
        $this->assertTrue($second->designation_deferred);
        $this->assertCount(1, $second->orientations);
        $this->assertDatabaseCount('episode_service_requests', 0);

        $care = $episode->orientations()->sole();
        app(AcceptCareOrientationAction::class)->execute($care, $this->actor);
        app(CompleteCareAndOrientToMedicineAction::class)
            ->executeForUnknownNeed($care, $this->actor);

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
        ]);
    }

    public function test_a_finalized_plan_replays_identically_but_rejects_a_change(): void
    {
        $ecg = $this->service('ECG', ReceptionRoutingMode::MedicineDirect);
        $episode = $this->episode();

        $first = $this->plan($episode, [[$ecg, 1]]);
        $second = $this->plan($episode, [[$ecg, 1]]);

        $this->assertSame($first->sole()->id, $second->sole()->id);
        $this->assertDatabaseCount('episode_service_requests', 1);
        $this->assertDatabaseCount('episode_orientations', 1);

        try {
            $this->plan($episode, [[$ecg, 2]]);
            $this->fail('Un plan finalisé ne doit pas accepter une autre quantité.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('catalog_lines', $exception->errors());
        }
    }

    public function test_emergency_keeps_both_queues_regardless_of_the_selected_route(): void
    {
        $injection = $this->service('INJECTION-IM', ReceptionRoutingMode::CareOnly);
        $episode = $this->episode(EpisodePriority::Emergency);

        $this->plan($episode, [[$injection, 1]]);

        $this->assertEqualsCanonicalizing(
            [CatalogModule::Care, CatalogModule::Medicine],
            $episode->orientations()->pluck('destination_module')->all(),
        );
    }

    private function episode(EpisodePriority $priority = EpisodePriority::Normal): Episode
    {
        $patient = Patient::query()->create([
            'patient_number' => 'M-'.fake()->unique()->numerify('######'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);

        return app(CreateEpisodeAction::class)->execute($patient, $priority);
    }

    private function service(string $code, ReceptionRoutingMode $route): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => CatalogItemType::Service,
            'module' => $route === ReceptionRoutingMode::CareOnly
                ? CatalogModule::Care
                : CatalogModule::Medicine,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => $route,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'amount' => '10000.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test',
            'created_by' => $this->actor->id,
        ]);

        return $item;
    }

    private function recordPerformedProcedure(Episode $episode, CatalogItem $item): void
    {
        $record = CareRecord::query()->create([
            'episode_id' => $episode->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $record->procedures()->create([
            'catalog_item_id' => $item->id,
            'catalog_item_uuid' => $item->uuid,
            'procedure_code' => $item->code,
            'procedure_name' => $item->name,
            'quantity' => 1,
            'performed_by' => $this->actor->id,
            'performed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{0: CatalogItem, 1: int|string}>  $lines
     */
    private function plan(Episode $episode, array $lines)
    {
        return app(PlanEpisodeRoutingAction::class)->execute(
            $episode,
            collect($lines)->map(fn (array $line) => [
                'catalog_item_uuid' => $line[0]->uuid,
                'quantity' => $line[1],
            ])->all(),
            $this->actor,
        );
    }
}
