<?php

namespace Tests\Feature\Medicine;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\BillableItemStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeFinancialMode;
use App\Enums\ReceptionRoutingMode;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequestItem;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-109 — un examen déjà demandé à la Réception n'est ni refacturé, ni
 * redemandé au médecin.
 *
 * Le cas réel : Mme R. arrive pour une « Échographie obstétricale ». La
 * Réception la planifie et la facture (ADR-068). Le médecin la reçoit, et
 * l'étape Paraclinique lui faisait chercher l'examen au catalogue — dont la
 * demande facturait une seconde fois depuis l'ADR-105.
 */
class PlannedParaclinicalBillingTest extends TestCase
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

    /** Le défaut signalé : 50 000 Ar facturés deux fois pour une échographie. */
    public function test_an_exam_planned_at_reception_is_never_billed_twice(): void
    {
        $exam = $this->imagingItem('ECHO-OBS', 'Échographie obstétricale');
        [$episode, $orientation] = $this->arrivalFor($exam);

        // La Réception a déjà facturé l'examen à l'arrivée.
        $this->assertSame(1, $this->billedCount($episode->id));

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->billedCount($episode->id), 'L’examen ne doit pas être facturé une seconde fois.');

        // La ligne de demande pointe la prestation de la Réception : la
        // facturation n'est pas seulement évitée, elle est rattachée.
        $line = ImagingRequestItem::query()->sole();
        $this->assertNotNull($line->billable_item_id);
        $this->assertSame(
            BillableItem::query()->where('episode_id', $episode->id)->value('id'),
            $line->billable_item_id,
        );
    }

    /**
     * Un second examen réellement demandé reste un second acte : la garde ne
     * doit pas transformer « déjà payé une fois » en « gratuit ensuite ».
     */
    public function test_a_second_request_for_the_same_exam_is_billed(): void
    {
        $exam = $this->imagingItem('ECHO-OBS', 'Échographie obstétricale');
        [$episode, $orientation] = $this->arrivalFor($exam);

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ])->assertSessionHasNoErrors();

        // Le résultat arrive : l'examen n'est plus « en attente », et le
        // médecin peut légitimement en redemander un.
        ImagingRequestItem::query()->sole()->forceFill([
            'result_value' => 'Grossesse évolutive.',
            'resulted_at' => now(),
            'resulted_by' => $this->doctor->id,
        ])->save();

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->billedCount($episode->id), 'Un second examen demandé est un second acte.');
    }

    /**
     * ADR-163 — retirer la demande ne retire pas ce que la Réception a facturé
     * (le patient est venu pour cet examen), et la libère : l'examen redemandé
     * ensuite la reprend au lieu d'être facturé une seconde fois.
     */
    public function test_a_withdrawn_request_frees_the_reception_billing_without_cancelling_it(): void
    {
        $exam = $this->imagingItem('ECHO-OBS', 'Échographie obstétricale');
        [$episode, $orientation] = $this->arrivalFor($exam);
        $receptionBilling = BillableItem::query()->where('episode_id', $episode->id)->sole();

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ])->assertSessionHasNoErrors();
        $request = $orientation->consultation()->firstOrFail()->imagingRequests()->sole();

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/paraclinical-requests/cancel", [
            'kind' => 'imaging',
            'uuid' => $request->uuid,
        ])->assertSessionHasNoErrors();

        $this->assertNotSame(BillableItemStatus::Cancelled, $receptionBilling->fresh()->status);

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->billedCount($episode->id), 'L’examen redemandé reprend la prestation de la Réception.');
    }

    /** Un examen que la Réception n'a pas planifié se facture normalement. */
    public function test_an_exam_never_planned_is_billed_by_the_request(): void
    {
        $planned = $this->imagingItem('ECHO-OBS', 'Échographie obstétricale');
        [$episode, $orientation] = $this->arrivalFor($planned);

        $other = $this->imagingItem('ECG', 'Électrocardiogramme');

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $other->uuid]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->billedCount($episode->id));
    }

    /**
     * Le besoin connu est servi à l'écran : le médecin ne le cherche pas,
     * il le trouve déjà dans sa demande (ADR-084 — jamais deux fois la même
     * saisie).
     */
    public function test_the_known_need_is_served_to_the_paraclinical_step(): void
    {
        $exam = $this->imagingItem('ECHO-OBS', 'Échographie obstétricale');
        [, $orientation] = $this->arrivalFor($exam);

        $planned = $this->actingAs($this->doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->assertOk()
            ->viewData('page')['props']['consultation']['planned_paraclinical'];

        $this->assertCount(1, $planned);
        $this->assertSame($exam->uuid, $planned[0]['catalog_item_uuid']);
        $this->assertSame('IMAGING', $planned[0]['module']);
        // L'écran doit pouvoir dire que transmettre ne refacture rien.
        $this->assertTrue($planned[0]['already_billed']);
    }

    /** Une fois la demande transmise, le besoin n'est plus « à transmettre ». */
    public function test_a_transmitted_need_leaves_the_list(): void
    {
        $exam = $this->imagingItem('ECHO-OBS', 'Échographie obstétricale');
        [, $orientation] = $this->arrivalFor($exam);

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ])->assertSessionHasNoErrors();

        $this->assertEmpty($this->actingAs($this->doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->viewData('page')['props']['consultation']['planned_paraclinical']);
    }

    private function billedCount(int $episodeId): int
    {
        return BillableItem::query()->where('episode_id', $episodeId)->count();
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function arrivalFor(CatalogItem $exam): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Malala',
            'last_name' => 'Rasoamifidy',
            'birth_date' => '1981-03-04',
            'sex' => 'F',
        ]);

        $episode = app(CreateEpisodeAction::class)->execute($patient);
        $episode->forceFill([
            'financial_mode' => EpisodeFinancialMode::Self,
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $this->doctor->id,
        ])->save();

        app(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $exam->uuid,
            'quantity' => 1,
        ]], $this->doctor);

        // La Réception confirme, ce qui facture la prestation planifiée
        // (ADR-068) : c'est ce chemin, et non le seul routage, qui crée le
        // `BillableItem` que le médecin ne doit pas dupliquer.
        app(CreateInvoiceAction::class)->execute($episode->patient, [
            'episode_uuid' => $episode->uuid,
            'catalog_lines' => [['catalog_item_uuid' => $exam->uuid, 'quantity' => 1]],
        ], $this->doctor);

        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        app(AcceptMedicineOrientationAction::class)->execute($orientation, $this->doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }

    private function imagingItem(string $code, string $name): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => $code.'-'.uniqid(),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $this->doctor->id,
            'updated_by' => $this->doctor->id,
        ]);

        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'category' => CatalogTariffCategory::Standard,
            'amount' => '50000.00',
            'currency' => 'MGA',
            'effective_from' => now()->subDay(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif initial du test.',
            'created_by' => $this->doctor->id,
        ]);

        return $item->fresh();
    }
}
