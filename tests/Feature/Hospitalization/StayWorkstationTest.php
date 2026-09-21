<?php

namespace Tests\Feature\Hospitalization;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CreatePrescriptionAction;
use App\Actions\Pharmacy\DispenseMedicinesAction;
use App\Actions\Pharmacy\PrepareDispenseInvoiceAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayEndReason;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicineForm;
use App\Enums\PharmacyDispenseStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CareOrder;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\HospitalStayNote;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\MedicalDischarge;
use App\Models\MedicalReferral;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use App\Support\CareRequestSummary;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-162 — le séjour, poste de travail du patient hospitalisé : tout s'y
 * fait, par les actions qui portent déjà les règles, sans rouvrir de
 * consultation.
 */
class StayWorkstationTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic', 'rivo.site.code' => 'M', 'rivo.site.name' => 'Mampikony']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->doctor = $this->userOf('MEDICINE');
    }

    public function test_the_daily_note_is_written_on_the_stay_and_never_rewritten(): void
    {
        [, $stay] = $this->admitted();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/notes", [
            'subjective' => 'Moins de douleurs abdominales',
            'assessment' => 'Évolution favorable',
            'plan' => 'Poursuivre la réhydratation',
        ])->assertSessionHasNoErrors();

        $note = HospitalStayNote::query()->sole();
        $this->assertSame($this->doctor->id, $note->written_by);
        $this->assertNull($note->objective);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/notes", ['objective' => ''])
            ->assertSessionHasErrors('subjective');

        $this->expectException(\LogicException::class);
        $note->update(['plan' => 'Réécrit']);
    }

    public function test_reception_reads_the_stay_but_not_the_notes_and_orders_nothing(): void
    {
        [, $stay] = $this->admitted();
        $reception = $this->userOf('RECEPTION');
        $reception->role->permissions()->syncWithoutDetaching([
            Permission::query()->where('name', 'hospitalization.view')->value('id'),
        ]);
        HospitalStayNote::query()->create([
            'hospital_stay_id' => $stay->id, 'subjective' => 'Note clinique',
            'written_at' => now(), 'written_by' => $this->doctor->id,
        ]);

        $this->actingAs($reception)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('notes', null)
                ->where('prescriptions', null)
                ->where('orderCapabilities.can_prescribe', false)
                ->where('orderCapabilities.can_write_note', false));

        foreach (['notes', 'ordonnances', 'analyses', 'imagerie', 'soins', 'transfert', 'sortie'] as $segment) {
            $this->actingAs($reception)->post("/hospitalisation/{$stay->uuid}/{$segment}", [])->assertForbidden();
        }
    }

    public function test_a_prescription_from_the_stay_opens_no_consultation_and_is_dispensed_without_waiting_for_payment(): void
    {
        [$episode, $stay] = $this->admitted();
        [$medicine, $lot] = $this->stockedMedicine();
        $consultations = $episode->consultations()->count();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/ordonnances", [
            'lines' => [[
                'manual' => false,
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 6,
                'dosage' => '1 g',
                'frequency' => '3 fois/jour',
                'duration' => '2 jours',
            ]],
        ])->assertSessionHasNoErrors();

        $prescription = Prescription::query()->sole();
        $this->assertNull($prescription->consultation_id);
        $this->assertSame($stay->id, $prescription->hospital_stay_id);
        $this->assertSame($episode->id, $prescription->episode_id);
        $this->assertSame($consultations, $episode->consultations()->count());

        $dispense = $prescription->pharmacyDispense()->with('lines')->sole();
        $this->assertTrue($dispense->isWardDispense());

        $pharmacist = $this->userOf('PHARMACY');
        $dispense = $this->app->make(PrepareDispenseInvoiceAction::class)->execute($dispense, $pharmacist);
        // Le patient est au lit : la délivrance n'attend pas la Caisse.
        $this->assertSame(PharmacyDispenseStatus::Ready, $dispense->status);
        $this->assertNotNull($dispense->invoice_id);

        $this->app->make(DispenseMedicinesAction::class)->execute($dispense->fresh(), [
            'lines' => [['uuid' => $dispense->lines->sole()->uuid, 'quantity' => 6]],
        ], $pharmacist);

        $this->assertSame(PharmacyDispenseStatus::Dispensed, $dispense->fresh()->status);
        $this->assertSame(14, $lot->fresh()->quantity_on_hand);
    }

    public function test_an_ordinary_dispense_still_waits_for_payment(): void
    {
        [$episode] = $this->admitted();
        [$medicine] = $this->stockedMedicine();
        $consultation = $episode->consultations()->firstOrFail();

        $prescription = $this->app->make(CreatePrescriptionAction::class)->execute($consultation, [[
            'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 2, 'dosage' => '1 g', 'frequency' => '1 fois/jour',
        ]], $this->doctor);
        $dispense = $this->app->make(PrepareDispenseInvoiceAction::class)
            ->execute($prescription->pharmacyDispense, $this->userOf('PHARMACY'));

        $this->assertFalse($dispense->isWardDispense());
        $this->assertSame(PharmacyDispenseStatus::AwaitingPayment, $dispense->status);
    }

    public function test_lab_and_imaging_requests_leave_from_the_stay_and_a_duplicate_is_refused(): void
    {
        [$episode, $stay] = $this->admitted();
        $analysis = $this->service('LAB-NFS', 'NFS', CatalogModule::Laboratory);
        $this->tariff($analysis, '12000.00');
        $echo = $this->service('ECHO-ABD', 'Échographie abdominale', CatalogModule::Imaging);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertSessionHasErrors('lab_request');

        $lab = LabRequest::query()->sole();
        $this->assertNull($lab->consultation_id);
        $this->assertSame($stay->id, $lab->hospital_stay_id);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => CatalogModule::Hospitalization->value,
            'destination_module' => CatalogModule::Laboratory->value,
        ]);
        $this->assertNotNull($lab->items->sole()->billable_item_id);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/imagerie", [
            'items' => [['catalog_item_uuid' => $echo->uuid]],
        ])->assertSessionHasNoErrors();

        $item = ImagingRequest::query()->sole()->items->sole();
        $stayOrientation = $stay->episodeOrientation;

        // Le compte rendu se saisit par la même fenêtre, adressée au séjour.
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$stayOrientation->uuid}/imaging-requests/{$item->uuid}/result", [
            'result_value' => '<p>Foie normal.</p>',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->resulted_at);

        $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('labRequests', 1)
                ->has('imagingRequests', 1)
                ->where('imagingRequests.0.items.0.can_record', false)
                ->where('orderOptions.stay_orientation_uuid', $stayOrientation->uuid));
    }

    public function test_care_from_the_stay_never_releases_the_passage_while_the_patient_is_in_bed(): void
    {
        [$episode, $stay] = $this->admitted();
        $act = CatalogItem::query()->create([
            'code' => 'CARE-PANS', 'name' => 'Pansement', 'type' => CatalogItemType::Service,
            'module' => CatalogModule::Care, 'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'clinician_orderable' => true, 'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
        ]);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/soins", [
            'items' => [['catalog_item_uuid' => $act->uuid, 'quantity' => 1]],
            'instructions' => 'Pansement quotidien',
        ])->assertSessionHasNoErrors();

        $order = CareOrder::query()->sole();
        $this->assertFalse($order->requires_return_to_medicine);
        $this->assertSame(
            'STAY_IN_BED',
            CareRequestSummary::followUp(collect([$order]))['code'],
        );

        // Retirer l'acte avant que les Soins ne l'aient pris.
        $this->actingAs($this->doctor)
            ->post("/hospitalisation/{$stay->uuid}/soins/{$order->items->sole()->uuid}/retirer")
            ->assertSessionHasNoErrors();
        $this->assertNotNull($order->items->sole()->fresh()->cancelled_at);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
    }

    public function test_the_discharge_is_pronounced_on_the_stay_and_ends_it(): void
    {
        [$episode, $stay] = $this->admitted();
        // La consultation qui a demandé l'hospitalisation est clôturée : plus
        // aucun service n'aura le patient après la sortie.
        EpisodeOrientation::query()->where('episode_id', $episode->id)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole()->complete($this->doctor);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/sortie", [
            'type' => 'NORMAL',
            'final_diagnosis' => 'Gastro-entérite guérie',
            'patient_condition' => 'Guéri',
            'discharged_at' => now()->subMinute()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $discharge = MedicalDischarge::query()->sole();
        $this->assertNull($discharge->consultation_id);
        $stay->refresh();
        $this->assertSame(HospitalStayStatus::Discharged, $stay->status);
        $this->assertSame(HospitalStayEndReason::Home, $stay->end_reason);
        $this->assertSame($discharge->id, $stay->medical_discharge_id);
        $this->assertNull($stay->active_key);
        $this->assertSame(EpisodeOrientationStatus::Completed, $stay->episodeOrientation->fresh()->status);
        $this->assertSame(EpisodeMedicalStatus::MedicallyDischarged, $episode->fresh()->medical_status);
        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->fresh()->administrative_status);
        // Le diagnostic final rejoint le séjour, la consultation close n'est pas réécrite.
        $this->assertTrue($stay->diagnoses()->where('description', 'Gastro-entérite guérie')->exists());
    }

    public function test_the_discharge_needs_a_final_diagnosis_and_never_takes_the_transfer_type(): void
    {
        [, $stay] = $this->admitted();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/sortie", [
            'type' => 'NORMAL', 'patient_condition' => 'Guéri', 'discharged_at' => now()->toDateTimeString(),
        ])->assertSessionHasErrors('final_diagnosis');

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/sortie", [
            'type' => 'TRANSFER', 'final_diagnosis' => 'X', 'patient_condition' => 'Stable',
            'transfer_destination' => 'CHU', 'discharged_at' => now()->toDateTimeString(),
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('medical_discharges', 0);
    }

    public function test_a_consultation_no_longer_discharges_a_patient_in_bed(): void
    {
        [$episode] = $this->admitted();
        // Une ancienne visite encore ouverte pendant le séjour.
        $visit = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Hospitalization, CatalogModule::Medicine, $this->doctor, 'Visite',
        );
        app(AcceptMedicineOrientationAction::class)->execute($visit, $this->doctor);

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$visit->uuid}/discharge", [
            'type' => 'NORMAL', 'final_diagnosis' => 'Guéri', 'patient_condition' => 'Guéri',
            'discharged_at' => now()->subMinute()->toDateTimeString(),
        ])->assertSessionHasErrors('medical_discharge');

        $this->assertDatabaseCount('medical_discharges', 0);
    }

    /**
     * Les autres sites de la clinique sont proposés au transfert ; un
     * établissement extérieur reste une saisie libre. Le site courant n'y
     * figure jamais : on ne se transfère pas chez soi.
     */
    public function test_the_other_clinic_sites_are_offered_as_transfer_destinations(): void
    {
        [, $stay] = $this->admitted();

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $destinations = collect($props['orderOptions']['transfer_destinations']);

        $this->assertSame(['A', 'B'], $destinations->pluck('code')->all());
        $this->assertSame('Clinique Saint Georges — Ambondromamy', $destinations->firstWhere('code', 'A')['destination']);
        $this->assertNotContains('M', $destinations->pluck('code')->all());

        // Un site choisi part tel quel sur la demande.
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/transfert", [
            'priority' => 'NORMAL', 'facility' => 'Clinique Saint Georges — Boriziny',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Clinique Saint Georges — Boriziny', MedicalReferral::query()->sole()->facility);

        // Sans le droit de demander un transfert, la liste n'est pas servie.
        $reader = $this->userOf('RECEPTION');
        $props = $this->actingAs($reader)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertSame([], $props['orderOptions']['transfer_destinations']);
    }

    public function test_a_transfer_is_requested_from_the_stay_and_ends_it_at_departure(): void
    {
        [$episode, $stay] = $this->admitted();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/transfert", [
            'priority' => 'URGENT', 'facility' => 'CHU Mahajanga',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/transfert", ['priority' => 'URGENT'])
            ->assertSessionHasErrors('referral');

        $referral = MedicalReferral::query()->sole();
        $this->assertSame($stay->id, $referral->hospital_stay_id);
        $this->assertNull($referral->consultation_id);
        $this->assertSame('Gastro-entérite aiguë', $referral->diagnosis);
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);

        $nurse = $this->userOf('NURSE');
        $this->actingAs($nurse)->post("/transferts/{$referral->uuid}/depart", [
            'facility' => 'CHU Mahajanga',
            'departed_at' => now()->subMinute()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(HospitalStayEndReason::Transfer, $stay->fresh()->end_reason);
        $this->assertSame(EpisodeMedicalStatus::Transferred, $episode->fresh()->medical_status);
    }

    public function test_service_visits_are_no_longer_opened(): void
    {
        [, $stay] = $this->admitted();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/visites")->assertNotFound();
    }

    /** @return array{0: Episode, 1: HospitalStay} */
    private function admitted(): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa', 'last_name' => 'Rakoto', 'birth_date' => '1990-01-01', 'sex' => 'F',
        ]);
        $this->actingAs($this->doctor);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = $this->service('CONSULT-'.uniqid(), 'Consultation générale', CatalogModule::Medicine, [
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $item->uuid, 'quantity' => 1,
        ]], $this->doctor);
        $episode->update(['financial_mode' => 'SELF', 'financial_context_completed_at' => now(), 'financial_context_completed_by' => $this->doctor->id]);
        $orientation = EpisodeOrientation::query()
            ->where('episode_id', $episode->id)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $this->doctor);

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        return [$episode->fresh(), HospitalStay::query()->where('episode_id', $episode->id)->sole()];
    }

    /** @return array{0: Medicine, 1: MedicineLot} */
    private function stockedMedicine(): array
    {
        $item = $this->service('PH-CEF', 'Ceftriaxone 1 g', CatalogModule::Pharmacy, [
            'type' => CatalogItemType::Medicine, 'unit' => 'flacon', 'stockable' => true,
        ]);
        $this->tariff($item, '2500.00');
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id, 'generic_name' => 'Ceftriaxone', 'form' => MedicineForm::Injectable,
            'strength' => '1 g', 'active' => true, 'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
        ]);
        $lot = MedicineLot::query()->create([
            'medicine_id' => $medicine->id, 'lot_number' => 'LOT-1', 'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(), 'quantity_on_hand' => 20, 'active' => true,
            'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
        ]);

        return [$medicine->load('catalogItem'), $lot];
    }

    private function service(string $code, string $name, CatalogModule $module, array $extra = []): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code, 'name' => $name, 'type' => CatalogItemType::Service, 'module' => $module,
            'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
            ...$extra,
        ]);
    }

    private function tariff(CatalogItem $item, string $amount): void
    {
        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id, 'amount' => $amount, 'currency' => 'MGA',
            'effective_from' => now()->subDay(), 'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test', 'created_by' => $this->doctor->id,
        ]);
    }

    private function userOf(string $code): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', $code)->value('id')]);
    }
}
