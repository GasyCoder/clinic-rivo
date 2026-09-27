<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\BillableItemStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ConsultationStepStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\ReceptionRoutingMode;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\ConsultationWorkflow;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-105 — un examen paraclinique demandé en consultation rejoint le compte
 * du patient, et sa demande ne retient plus la clôture.
 */
class ParaclinicalBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->doctor = User::factory()->create([
            'role_id' => Role::query()->where('code', 'MEDICINE')->value('id'),
        ]);
    }

    public function test_an_analysis_requested_in_consultation_reaches_the_patient_account(): void
    {
        [$episode, $orientation] = $this->consultation();
        $analysis = $this->paraclinicalItem('NFS', 'Numération formule sanguine', CatalogModule::Laboratory, '12000.00');

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertRedirect();

        $billable = BillableItem::query()
            ->where('episode_id', $episode->id)
            ->where('description', 'like', '%Numération%')
            ->sole();

        $this->assertSame('12000.00', $billable->total_amount);
        $this->assertSame(BillableItemStatus::Pending, $billable->status);

        // La ligne de la demande porte l'élément qu'elle a produit : une
        // annulation retrouve exactement ce qu'elle doit retirer.
        $line = LabRequest::query()->where('episode_id', $episode->id)->sole()->items()->sole();
        $this->assertSame($billable->id, $line->billable_item_id);
    }

    public function test_an_ecg_requested_in_consultation_reaches_the_patient_account(): void
    {
        [$episode, $orientation] = $this->consultation();
        $ecg = $this->paraclinicalItem('ECG', 'Électrocardiogramme', CatalogModule::Imaging, '25000.00');

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $ecg->uuid]],
        ])->assertRedirect();

        $this->assertSame('25000.00', BillableItem::query()
            ->where('episode_id', $episode->id)
            ->where('description', 'like', '%Électrocardiogramme%')
            ->sole()
            ->total_amount);
    }

    /**
     * La demande est partie au Laboratoire : elle ne peut plus retenir la
     * clôture sur un clic de validation.
     */
    public function test_a_transmitted_request_resolves_its_step_and_stops_blocking_closure(): void
    {
        [, $orientation] = $this->consultation();
        $analysis = $this->paraclinicalItem('NFS', 'Numération formule sanguine', CatalogModule::Laboratory, '12000.00');

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertRedirect();

        $consultation = $orientation->consultation()->sole();
        $step = $consultation->steps()->where('step', 'paraclinique')->sole();

        $this->assertSame(ConsultationStepStatus::Completed, $step->status);
        $this->assertSame($this->doctor->id, $step->completed_by);

        $blockers = app(ConsultationWorkflow::class)->closureBlockerMessages($consultation);
        $this->assertEmpty(array_filter(
            $blockers,
            fn (string $message) => str_contains($message, 'paraclinique'),
        ));
    }

    /** Retirer une demande retire ce qu'elle avait mis au compte du patient. */
    public function test_withdrawing_a_request_cancels_what_it_had_billed(): void
    {
        [$episode, $orientation] = $this->consultation();
        $analysis = $this->paraclinicalItem('NFS', 'Numération formule sanguine', CatalogModule::Laboratory, '12000.00');

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertRedirect();

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/complementary-exams", [
            'required' => false,
            'withdraw_confirmed' => true,
        ])->assertRedirect();

        $this->assertSame(
            BillableItemStatus::Cancelled,
            BillableItem::query()->where('episode_id', $episode->id)
                ->where('description', 'like', '%Numération%')
                ->sole()
                ->status,
        );
    }

    /**
     * ADR-163 — retirer **une** demande (et non répondre « Non » à toutes) la
     * retirait du Laboratoire mais la laissait à payer : ce chemin oubliait
     * l'ADR-105.
     */
    public function test_withdrawing_one_request_also_cancels_what_it_had_billed(): void
    {
        [$episode, $orientation] = $this->consultation();
        $analysis = $this->paraclinicalItem('NFS', 'Numération formule sanguine', CatalogModule::Laboratory, '12000.00');

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertRedirect();
        $request = $orientation->consultation()->firstOrFail()->labRequests()->sole();

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/paraclinical-requests/cancel", [
            'kind' => 'lab',
            'uuid' => $request->uuid,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            BillableItemStatus::Cancelled,
            BillableItem::query()->where('episode_id', $episode->id)
                ->where('description', 'like', '%Numération%')
                ->sole()
                ->status,
        );
    }

    /**
     * Un tarif absent ne doit jamais empêcher la demande : l'analyse part au
     * Laboratoire, la Réception régularise ensuite (ADR-054, ADR-072).
     */
    public function test_a_missing_tariff_never_blocks_the_request(): void
    {
        [$episode, $orientation] = $this->consultation();
        $analysis = $this->paraclinicalItem('NFS', 'Numération formule sanguine', CatalogModule::Laboratory, null);

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertRedirect();

        $this->assertSame(1, LabRequest::query()->where('episode_id', $episode->id)->count());
        $this->assertNull(LabRequest::query()->where('episode_id', $episode->id)->sole()->items()->sole()->billable_item_id);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function consultation(): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient);
        $episode->forceFill([
            'financial_mode' => EpisodeFinancialMode::Self,
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $this->doctor->id,
        ])->save();

        $consultationItem = $this->paraclinicalItem(
            'CONSULT-GEN',
            'Consultation générale',
            CatalogModule::Medicine,
            '20000.00',
            selectable: true,
        );

        app(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $consultationItem->uuid,
            'quantity' => 1,
        ]], $this->doctor);
        // ADR-177 — une prestation d'arrivée n'ouvre plus de file : l'orientation
        // vers ce service est désormais un geste réel, posé ici explicitement.
        app(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Medicine, $this->doctor);

        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        app(AcceptMedicineOrientationAction::class)->execute($orientation, $this->doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }

    private function paraclinicalItem(
        string $code,
        string $name,
        CatalogModule $module,
        ?string $amount,
        bool $selectable = false,
    ): CatalogItem {
        $item = CatalogItem::query()->create([
            'code' => $code.'-'.uniqid(),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => 'examen',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => $selectable,
            'reception_routing_mode' => $selectable ? ReceptionRoutingMode::MedicineDirect : null,
            'created_by' => $this->doctor->id,
            'updated_by' => $this->doctor->id,
        ]);

        if ($amount !== null) {
            CatalogTariff::query()->create([
                'catalog_item_id' => $item->id,
                'tariff_category' => CatalogTariffCategory::Standard,
                'amount' => $amount,
                'currency' => 'MGA',
                'effective_from' => now(),
                'active_key' => 'CURRENT',
                'change_reason' => 'Tarif de test ADR-105',
                'created_by' => $this->doctor->id,
            ]);
        }

        return $item;
    }
}
