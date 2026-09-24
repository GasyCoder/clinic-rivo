<?php

namespace Tests\Feature\ClinicalFlow;

use App\Actions\Care\CompleteCareAndOrientToMedicineAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Episode\SetEpisodeFinancialContextAction;
use App\Actions\Episode\TakeChargeOfEpisodeAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Enums\SurgicalRequestOrigin;
use App\Enums\SurgicalRequestStatus;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-177 — ce que la sélection de la Réception crée, et ce qu'elle ne crée plus.
 *
 * Le besoin dit pourquoi le patient vient : il est conservé en instantané (tarif,
 * couverture, parcours de la désignation). Il n'ouvre plus aucune file Soins,
 * Médecine ou Maternité — le passage est visible de tous les services autorisés
 * et chacun le prend en charge par un vrai geste. Seules restent les demandes
 * techniques (analyses, acte du bloc) et l'exception de l'urgence.
 */
class EpisodeRoutingPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.code' => 'M']);
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);
        $permission = Permission::query()->create(['name' => 'episodes.create']);
        $role->permissions()->attach($permission);
        $this->actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->actor);
    }

    public function test_a_direct_medicine_designation_opens_no_queue_and_snapshots_the_route(): void
    {
        $ecg = $this->service('ECG', ReceptionRoutingMode::MedicineDirect);
        $episode = $this->episode();

        $requests = $this->plan($episode, [[$ecg, 2]]);

        $this->assertCount(1, $requests);
        $this->assertSame(ReceptionRoutingMode::MedicineDirect, $requests->first()->routing_mode);
        $this->assertSame('2.00', $requests->first()->quantity);
        $this->assertNotNull($episode->fresh()->service_plan_finalized_at);
        $this->assertFalse($episode->fresh()->designation_deferred);
        // Le besoin ne décide plus qui voit le passage : aucune file n'est ouverte.
        $this->assertDatabaseCount('episode_orientations', 0);

        $ecg->update(['reception_routing_mode' => ReceptionRoutingMode::CareOnly]);
        $this->assertSame(
            ReceptionRoutingMode::MedicineDirect,
            $episode->serviceRequests()->sole()->routing_mode,
        );
    }

    /**
     * Le parcours de la désignation garde un seul rôle : proposer la suite des
     * Soins (ADR-166). Une consultation générale propose, à la fin des soins, la
     * transmission au médecin — une vraie orientation, créée par ce geste.
     */
    public function test_care_then_medicine_opens_no_queue_but_still_proposes_the_doctor_after_care(): void
    {
        $consultation = $this->service('CONSULT-GEN', ReceptionRoutingMode::CareThenMedicine);
        $episode = $this->episode();
        $this->plan($episode, [[$consultation, 1]]);

        $this->assertDatabaseCount('episode_orientations', 0);

        $care = $this->takeCharge($episode, CatalogModule::Care);
        $this->assertSame(CatalogModule::Reception, $care->source_module);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $care->status);
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

        $this->assertTrue($episode->serviceRequests()->sole()->care_requires_allergy_check);

        $injection->update(['care_requires_allergy_check' => false]);
        $this->assertTrue($episode->serviceRequests()->sole()->care_requires_allergy_check);

        $care = $this->takeCharge($episode, CatalogModule::Care);
        $this->recordPerformedProcedure($episode, $injection);
        app(CompleteCareAndOrientToMedicineAction::class)->execute($care, $this->actor);

        $this->assertDatabaseMissing('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
        ]);
    }

    public function test_a_mixed_plan_opens_no_queue_and_care_still_proposes_the_doctor(): void
    {
        $injection = $this->service('INJECTION-IM', ReceptionRoutingMode::CareOnly);
        $ecg = $this->service('ECG', ReceptionRoutingMode::MedicineDirect);
        $episode = $this->episode();
        $this->plan($episode, [[$injection, 1], [$ecg, 1]]);

        $this->assertDatabaseCount('episode_orientations', 0);

        $care = $this->takeCharge($episode, CatalogModule::Care);
        app(CompleteCareAndOrientToMedicineAction::class)->execute($care, $this->actor);

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
            'status' => 'PENDING',
        ]);
    }

    /** « Besoin à préciser » ne force plus les Soins : le passage est simplement ouvert. */
    public function test_unknown_need_is_durable_idempotent_and_no_longer_forces_care(): void
    {
        $episode = $this->episode();
        $planner = app(PlanEpisodeRoutingAction::class);

        $first = $planner->planUnknownNeed($episode, $this->actor);
        $second = $planner->planUnknownNeed($episode, $this->actor);

        $this->assertTrue($first->designation_deferred);
        $this->assertTrue($second->designation_deferred);
        $this->assertCount(0, $second->orientations);
        $this->assertDatabaseCount('episode_service_requests', 0);

        // N'importe quel service autorisé peut le prendre ; ici les Soins, qui orientent explicitement.
        $care = $this->takeCharge($episode, CatalogModule::Care);
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
        $this->assertDatabaseCount('episode_orientations', 0);

        try {
            $this->plan($episode, [[$ecg, 2]]);
            $this->fail('Un plan finalisé ne doit pas accepter une autre quantité.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('catalog_lines', $exception->errors());
        }
    }

    /** L'exception assumée (ADR-021, ADR-056) : une urgence n'est pas une suggestion. */
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

    public function test_a_reception_analysis_opens_laboratory_and_creates_the_operational_request(): void
    {
        $analysis = $this->service('LAB-NFS', ReceptionRoutingMode::LaboratoryDirect);
        $episode = $this->episode();

        $this->plan($episode, [[$analysis, 1]]);

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => CatalogModule::Reception->value,
            'destination_module' => CatalogModule::Laboratory->value,
            'status' => 'PENDING',
        ]);
        $request = LabRequest::query()->where('episode_id', $episode->id)->sole();
        $this->assertNull($request->consultation_id);
        $this->assertNull($request->source_orientation_id);
        $this->assertSame($this->actor->id, $request->requested_by);
        $this->assertDatabaseHas('lab_request_items', [
            'lab_request_id' => $request->id,
            'catalog_item_id' => $analysis->id,
            'catalog_item_code_snapshot' => 'LAB-NFS',
        ]);
    }

    /**
     * Un acte Maternité annoncé à l'arrivée n'ouvre plus la file Maternité : la
     * Maternité voit le passage et le prend en charge par un vrai geste. Même
     * alors, le dossier Maternité ne naît qu'à la première saisie de la sage-femme.
     */
    public function test_a_reception_maternity_act_opens_no_queue_and_maternity_takes_the_same_episode(): void
    {
        $act = $this->service('MAT-CONSULT-PRENATAL', ReceptionRoutingMode::MaternityDirect);
        $episode = $this->episode();

        $this->plan($episode, [[$act, 1]]);

        $this->assertDatabaseCount('episode_orientations', 0);
        $this->assertDatabaseCount('maternity_records', 0);
        $this->assertDatabaseCount('lab_requests', 0);

        $maternity = $this->takeCharge($episode, CatalogModule::Maternity);

        $this->assertSame($episode->id, $maternity->episode_id);
        $this->assertSame(CatalogModule::Maternity, $maternity->destination_module);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $maternity->status);
        $this->assertSame(0, MaternityRecord::query()->where('episode_id', $episode->id)->count());
    }

    public function test_a_reception_surgical_act_opens_the_block_and_creates_its_request(): void
    {
        $act = $this->service('SURG-HERNIE-INGUINALE', ReceptionRoutingMode::SurgeryDirect);
        $episode = $this->episode();

        $this->plan($episode, [[$act, 1]]);

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => CatalogModule::Reception->value,
            'destination_module' => CatalogModule::Surgery->value,
            'status' => 'PENDING',
        ]);

        $request = SurgicalRequest::query()->where('episode_id', $episode->id)->sole();
        // Le bloc reçoit un dossier à programmer, jamais un dossier programmé :
        // ni chirurgien, ni date ne sont décidés à l'accueil (ADR-159).
        $this->assertSame(SurgicalRequestStatus::Pending, $request->status);
        $this->assertSame(SurgicalRequestOrigin::Reception, $request->origin);
        $this->assertSame($act->id, $request->catalog_item_id);
        $this->assertSame('SURG-HERNIE-INGUINALE', $request->procedure_name);
        $this->assertNull($request->surgeon_id);
        $this->assertNull($request->scheduled_at);
    }

    public function test_replaying_the_arrival_never_opens_two_files_at_the_block(): void
    {
        $act = $this->service('SURG-ABCES', ReceptionRoutingMode::SurgeryDirect);
        $episode = $this->episode();

        $this->plan($episode, [[$act, 1]]);
        $this->plan($episode, [[$act, 1]]);

        $this->assertDatabaseCount('surgical_requests', 1);
    }

    private function takeCharge(Episode $episode, CatalogModule $module): EpisodeOrientation
    {
        return app(TakeChargeOfEpisodeAction::class)->execute($episode->fresh(), $module, $this->actor);
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

        $episode = app(CreateEpisodeAction::class)->execute($patient, $priority);

        if ($priority !== EpisodePriority::Emergency) {
            $episode = app(SetEpisodeFinancialContextAction::class)->execute(
                $episode,
                EpisodeFinancialMode::Self,
                [],
                $this->actor,
            );
        }

        return $episode;
    }

    private function service(string $code, ReceptionRoutingMode $route): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => CatalogItemType::Service,
            'module' => match ($route) {
                ReceptionRoutingMode::CareOnly => CatalogModule::Care,
                ReceptionRoutingMode::LaboratoryDirect => CatalogModule::Laboratory,
                ReceptionRoutingMode::MaternityDirect => CatalogModule::Maternity,
                ReceptionRoutingMode::SurgeryDirect => CatalogModule::Surgery,
                default => CatalogModule::Medicine,
            },
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
