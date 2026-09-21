<?php

namespace Tests\Feature\Hospitalization;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\BillableItemStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ClinicalSuggestionSource;
use App\Enums\ConsultationOrientationStatus;
use App\Enums\ConsultationStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalRequestStatus;
use App\Enums\MedicineForm;
use App\Enums\ReceptionRoutingMode;
use App\Enums\SurgicalRequestStatus;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\ClinicalProtocol;
use App\Models\Consultation;
use App\Models\DiagnosticCatalog;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\LabRequest;
use App\Models\MedicalReferral;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\PrescriptionLine;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-163 — revenir sur un geste fait depuis le séjour, sans rien effacer ; et
 * les limites que l'ADR-162 avait laissées ouvertes.
 */
class StayReversalsTest extends TestCase
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

    // ── Transfert au bloc ────────────────────────────────────────────────

    public function test_a_transfer_to_the_block_is_withdrawn_while_the_block_has_not_scheduled_it(): void
    {
        [$episode, $stay] = $this->admitted();
        $act = $this->surgeryAct('SURG-APPENDICE', 'Appendicectomie');

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid, 'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();
        $request = SurgicalRequest::query()->where('episode_id', $episode->id)->sole();

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertTrue($props['surgeries'][0]['can_cancel']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/bloc/{$request->uuid}/annuler", [
            'reason' => 'Amélioration clinique',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $request->refresh();
        $this->assertSame(SurgicalRequestStatus::Cancelled, $request->status);
        $this->assertSame($this->doctor->id, $request->cancelled_by);
        $this->assertSame('Amélioration clinique', $request->cancellation_reason);
        // Rien n'est effacé : la demande reste, annulée.
        $this->assertDatabaseCount('surgical_requests', 1);
        // L'orientation vers le bloc ne sert plus à personne.
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Surgery->value,
            'status' => EpisodeOrientationStatus::Cancelled->value,
        ]);
        // Le patient n'a jamais quitté son lit.
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hospitalization.surgery.cancel']);
    }

    public function test_once_the_block_has_scheduled_the_ward_can_no_longer_withdraw_it(): void
    {
        [$episode, $stay] = $this->admitted();
        $act = $this->surgeryAct('SURG-APPENDICE', 'Appendicectomie');
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
            'catalog_item_uuid' => $act->uuid, 'priority' => 'NORMAL',
        ]);
        $request = SurgicalRequest::query()->where('episode_id', $episode->id)->sole();
        $request->schedule($this->userOf('SURGERY'), now()->addDay()->toDateTimeString());

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertFalse($props['surgeries'][0]['can_cancel']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/bloc/{$request->uuid}/annuler")
            ->assertSessionHasErrors('surgical_request');
        $this->assertSame(SurgicalRequestStatus::Scheduled, $request->fresh()->status);
    }

    public function test_withdrawing_one_intervention_keeps_the_block_orientation_of_another(): void
    {
        [$episode, $stay] = $this->admitted();
        foreach (['SURG-A' => 'Intervention A', 'SURG-B' => 'Intervention B'] as $code => $name) {
            $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/bloc", [
                'catalog_item_uuid' => $this->surgeryAct($code, $name)->uuid, 'priority' => 'NORMAL',
            ])->assertSessionHasNoErrors();
        }
        $first = SurgicalRequest::query()->where('episode_id', $episode->id)->orderBy('id')->first();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/bloc/{$first->uuid}/annuler")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Surgery->value,
            'status' => EpisodeOrientationStatus::Pending->value,
        ]);
    }

    /** Le cas de la capture : la visite de service a transmis « Chirurgie ». */
    public function test_a_block_request_sent_by_a_ward_round_is_withdrawn_with_its_conduite(): void
    {
        [$episode, $stay] = $this->admitted();
        $visit = $this->legacyWardRound($episode);
        $act = $this->surgeryAct('SURG-CURETAGE', 'Curetage chirurgical de l’utérus');
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$visit->uuid}/surgical-referrals", [
            'catalog_item_uuid' => $act->uuid, 'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();
        $request = SurgicalRequest::query()->where('episode_id', $episode->id)->sole();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/bloc/{$request->uuid}/annuler")->assertSessionHasNoErrors();

        $this->assertSame(SurgicalRequestStatus::Cancelled, $request->fresh()->status);
        $consultation = $visit->consultation()->firstOrFail();
        $this->assertSame(ConsultationOrientationStatus::Cancelled, $consultation->orientations()->sole()->status);
        // La visite encore ouverte devra choisir une autre conduite pour clôturer.
        $this->assertNull($consultation->fresh()->decision);
    }

    // ── Visite de service ────────────────────────────────────────────────

    public function test_a_ward_round_is_cancelled_not_erased_and_takes_its_pending_block_request_with_it(): void
    {
        [$episode, $stay] = $this->admitted();
        $visit = $this->legacyWardRound($episode);
        $act = $this->surgeryAct('SURG-CURETAGE', 'Curetage chirurgical de l’utérus');
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$visit->uuid}/surgical-referrals", [
            'catalog_item_uuid' => $act->uuid, 'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertSame($visit->uuid, $props['openConsultations'][0]['uuid']);
        $this->assertTrue($props['openConsultations'][0]['can_cancel']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/visites/{$visit->uuid}/annuler", [
            'reason' => 'Ouverte par erreur',
        ])->assertSessionHasNoErrors();

        $consultation = Consultation::query()->where('episode_orientation_id', $visit->id)->sole();
        $this->assertSame(ConsultationStatus::Cancelled, $consultation->status);
        $this->assertSame('Ouverte par erreur', $consultation->cancellation_reason);
        $this->assertSame(EpisodeOrientationStatus::Cancelled, $visit->fresh()->status);
        $this->assertSame(SurgicalRequestStatus::Cancelled, SurgicalRequest::query()->sole()->status);
        // Le patient reste au lit, et la visite reste lisible.
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);
        // Annulée, elle quitte la page du séjour ; son dossier reste au passage.
        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertSame([], $props['openConsultations']);
        $this->actingAs($this->doctor)->get("/passages/{$episode->uuid}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'hospitalization.visit.cancel']);
    }

    public function test_a_ward_round_that_posed_a_diagnosis_is_closed_not_cancelled(): void
    {
        [$episode, $stay] = $this->admitted();
        $visit = $this->legacyWardRound($episode);
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$visit->uuid}/diagnoses", [
            'type' => 'FINAL', 'description' => 'Pneumopathie',
        ])->assertSessionHasNoErrors();

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertFalse($props['openConsultations'][0]['can_cancel']);
        $this->assertContains('Elle porte un diagnostic.', $props['openConsultations'][0]['cancel_blockers']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/visites/{$visit->uuid}/annuler")
            ->assertSessionHasErrors('visit');
        $this->assertSame(ConsultationStatus::InProgress, $visit->consultation()->firstOrFail()->status);
    }

    public function test_only_the_doctor_who_opened_the_ward_round_cancels_it(): void
    {
        [$episode, $stay] = $this->admitted();
        $visit = $this->legacyWardRound($episode);

        $this->actingAs($this->userOf('MEDICINE'))->post("/hospitalisation/{$stay->uuid}/visites/{$visit->uuid}/annuler")
            ->assertSessionHasErrors('visit');
        $this->assertSame(EpisodeOrientationStatus::InProgress, $visit->fresh()->status);
    }

    public function test_cancelling_the_last_open_ward_round_after_discharge_frees_the_passage(): void
    {
        [$episode, $stay] = $this->admitted();
        $this->closeAdmissionConsultation($episode);
        $visit = $this->legacyWardRound($episode);
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/sortie", $this->discharge())->assertSessionHasNoErrors();
        // Le séjour est terminé, mais la visite ouverte retient le passage.
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/visites/{$visit->uuid}/annuler")->assertSessionHasNoErrors();

        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->fresh()->administrative_status);
    }

    // ── Consultations restées ouvertes (limite de l'ADR-162) ──────────────

    public function test_the_stay_names_the_consultation_left_open_and_closes_it_when_nothing_is_missing(): void
    {
        [$episode, $stay] = $this->admitted();
        $orientation = $this->admissionOrientation($episode);

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertCount(1, $props['openConsultations']);
        $this->assertSame('ADMISSION', $props['openConsultations'][0]['kind']);
        $this->assertFalse($props['openConsultations'][0]['can_close']);
        $this->assertNotEmpty($props['openConsultations'][0]['closure_blockers']);

        $this->completeSteps($orientation);
        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertTrue($props['openConsultations'][0]['can_close']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/consultations/{$orientation->uuid}/cloturer")
            ->assertSessionHasNoErrors();

        $this->assertSame(ConsultationStatus::Completed, $orientation->consultation()->firstOrFail()->status);
        // Le patient reste au lit : la consultation d'admission ne termine pas le séjour.
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertSame([], $props['openConsultations']);
    }

    // ── Examens et transfert ─────────────────────────────────────────────

    public function test_an_exam_requested_from_the_stay_is_withdrawn_and_unbilled(): void
    {
        [$episode, $stay] = $this->admitted();
        $exam = $this->service('LAB-NFS', 'NFS', CatalogModule::Laboratory);
        $this->tariff($exam, '12000.00');

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ])->assertSessionHasNoErrors();
        $request = LabRequest::query()->where('hospital_stay_id', $stay->id)->sole();
        $billable = BillableItem::query()->where('catalog_item_id', $exam->id)->sole();
        $this->assertSame(BillableItemStatus::Pending, $billable->status);

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertTrue($props['labRequests'][0]['can_cancel']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/analyses/{$request->uuid}/retirer", [
            'reason' => 'Finalement inutile',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($request->fresh()->cancelled_at);
        $this->assertSame(BillableItemStatus::Cancelled, $billable->fresh()->status);
    }

    public function test_an_exam_with_a_result_is_never_withdrawn(): void
    {
        [, $stay] = $this->admitted();
        $exam = $this->service('LAB-NFS', 'NFS', CatalogModule::Laboratory);
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $exam->uuid]],
        ]);
        $request = LabRequest::query()->where('hospital_stay_id', $stay->id)->sole();
        $request->items()->update(['resulted_at' => now(), 'result_value' => 'Normal']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/analyses/{$request->uuid}/retirer")
            ->assertSessionHasErrors('request');
        $this->assertNull($request->fresh()->cancelled_at);
    }

    public function test_a_transfer_is_withdrawn_until_the_patient_leaves(): void
    {
        [$episode, $stay] = $this->admitted();
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/transfert", [
            'priority' => 'URGENT', 'facility' => 'CHU Mahajanga',
        ])->assertSessionHasNoErrors();
        $referral = MedicalReferral::query()->where('hospital_stay_id', $stay->id)->sole();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/transfert/{$referral->uuid}/annuler", [
            'reason' => 'Établissement indisponible',
        ])->assertSessionHasNoErrors();

        $this->assertSame(MedicalRequestStatus::Cancelled, $referral->fresh()->status);
        $this->assertSame(EpisodeOrientationStatus::Cancelled, EpisodeOrientation::query()->findOrFail($referral->episode_orientation_id)->status);
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
        // Un nouveau transfert redevient possible.
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/transfert", ['priority' => 'NORMAL'])->assertSessionHasNoErrors();
    }

    // ── Le séjour ne s'annule plus dès qu'il a eu lieu (limite de l'ADR-160) ──

    public function test_a_stay_with_a_daily_note_is_no_longer_cancelled_by_changing_the_conduite(): void
    {
        [$episode, $stay] = $this->admitted();
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/notes", [
            'subjective' => 'Se sent mieux',
        ])->assertSessionHasNoErrors();

        $orientation = $this->admissionOrientation($episode);
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/orientation", [
            'type' => 'SURGERY',
        ])->assertSessionHasErrors('orientation');

        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
    }

    // ── Propositions d'ordonnance au séjour (limite de l'ADR-162) ─────────

    public function test_the_stay_proposes_the_protocol_of_a_posed_diagnosis_and_keeps_its_origin(): void
    {
        [$episode, $stay] = $this->admitted();
        $diagnosis = DiagnosticCatalog::query()->create(['code' => 'J18', 'name' => 'Pneumopathie', 'is_active' => true]);
        $medicine = $this->stockedMedicine();
        $protocol = ClinicalProtocol::query()->create([
            'diagnostic_catalog_id' => $diagnosis->id, 'name' => 'Pneumopathie de l’adulte',
            'indications' => ['toux'], 'is_active' => true,
            'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
        ]);
        $protocol->lines()->create(['medicine_id' => $medicine->id, 'dosage' => '1 g', 'frequency' => '1 fois/jour', 'duration' => '7 jours', 'quantity' => 7, 'sort_order' => 1]);

        // Le diagnostic conclu sur le séjour suffit (ADR-147).
        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/diagnostics", [
            'diagnostic_catalog_uuid' => $diagnosis->uuid,
        ])->assertSessionHasNoErrors();

        $props = $this->actingAs($this->doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $group = $props['prescriptionSuggestions']['prescription']['groups'][0];
        $this->assertSame('PROTOCOL', $group['source']);
        $this->assertSame($medicine->catalogItem->uuid, $group['lines'][0]['medicine_uuid']);
        $this->assertTrue($group['lines'][0]['available']);

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/ordonnances", ['lines' => [[
            'manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 7,
            'dosage' => '1 g', 'frequency' => '1 fois/jour', 'duration' => '7 jours',
            'suggestion_source' => 'PROTOCOL', 'suggestion_protocol_uuid' => $protocol->uuid,
        ]]])->assertSessionHasNoErrors();

        $line = PrescriptionLine::query()->sole();
        $this->assertSame(ClinicalSuggestionSource::Protocol, $line->suggestion_source);
        $this->assertSame($protocol->id, $line->clinical_protocol_id);
    }

    public function test_a_forged_suggestion_origin_is_refused_on_the_stay(): void
    {
        [, $stay] = $this->admitted();
        $medicine = $this->stockedMedicine();

        $this->actingAs($this->doctor)->post("/hospitalisation/{$stay->uuid}/ordonnances", ['lines' => [[
            'manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 2,
            'dosage' => '1 g', 'frequency' => '1 fois/jour', 'suggestion_source' => 'CLINIC_PRACTICE',
        ]]])->assertSessionHasErrors('lines.0.suggestion_source');

        $this->assertDatabaseCount('prescriptions', 0);
    }

    // ── Aides ────────────────────────────────────────────────────────────

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
        $orientation = $this->admissionOrientation($episode);
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $this->doctor);

        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        return [$episode->fresh(), HospitalStay::query()->where('episode_id', $episode->id)->sole()];
    }

    private function admissionOrientation(Episode $episode): EpisodeOrientation
    {
        return EpisodeOrientation::query()
            ->where('episode_id', $episode->id)
            ->where('destination_module', CatalogModule::Medicine->value)
            ->where('source_module', '!=', CatalogModule::Hospitalization->value)
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * Une visite de service ouverte avant l'ADR-162, reconstruite par les vraies
     * actions. Comme alors, la consultation d'admission est d'abord clôturée :
     * l'orientation Médecine est unique par passage (`active_key`).
     */
    private function legacyWardRound(Episode $episode): EpisodeOrientation
    {
        if ($this->admissionOrientation($episode)->fresh()->status === EpisodeOrientationStatus::InProgress) {
            $this->closeAdmissionConsultation($episode);
        }

        $visit = app(CreateEpisodeOrientationAction::class)->execute(
            $episode->fresh(), CatalogModule::Hospitalization, CatalogModule::Medicine, $this->doctor, 'Visite de service.',
        );
        $visit = app(AcceptMedicineOrientationAction::class)->execute($visit, $this->doctor);
        $episode->fresh()->update(['medical_status' => EpisodeMedicalStatus::Hospitalized]);

        return $visit;
    }

    private function completeSteps(EpisodeOrientation $orientation): void
    {
        $this->actingAs($this->doctor)->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Vomissements', 'reason' => '<p>Vomissements depuis 2 jours</p>', 'current_treatments' => [],
        ]);
        $this->actingAs($this->doctor)->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => '<p>Pli cutané persistant</p>',
        ]);
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL', 'description' => 'Gastro-entérite aiguë',
        ]);
        foreach ([['consultation', 'COMPLETE'], ['examen', 'COMPLETE'], ['paraclinique', 'SKIP'], ['ordonnance', 'SKIP']] as [$step, $intent]) {
            $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/steps", ['step' => $step, 'intent' => $intent])
                ->assertSessionHasNoErrors();
        }
    }

    private function closeAdmissionConsultation(Episode $episode): void
    {
        $orientation = $this->admissionOrientation($episode);
        $this->completeSteps($orientation);
        $this->actingAs($this->doctor)->post("/medicine/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function discharge(): array
    {
        return [
            'type' => 'NORMAL',
            'discharged_at' => now()->format('Y-m-d H:i'),
            'final_diagnosis' => 'Gastro-entérite aiguë',
            'patient_condition' => 'Amélioré',
        ];
    }

    private function surgeryAct(string $code, string $name): CatalogItem
    {
        return $this->service($code, $name, CatalogModule::Surgery, ['unit' => 'intervention']);
    }

    private function stockedMedicine(): Medicine
    {
        $item = $this->service('PH-CEF', 'Ceftriaxone 1 g', CatalogModule::Pharmacy, [
            'type' => CatalogItemType::Medicine, 'unit' => 'flacon', 'stockable' => true,
        ]);
        $this->tariff($item, '2500.00');
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id, 'generic_name' => 'Ceftriaxone', 'form' => MedicineForm::Injectable,
            'strength' => '1 g', 'active' => true, 'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
        ]);
        MedicineLot::query()->create([
            'medicine_id' => $medicine->id, 'lot_number' => 'LOT-1', 'received_at' => now()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(), 'quantity_on_hand' => 20, 'active' => true,
            'created_by' => $this->doctor->id, 'updated_by' => $this->doctor->id,
        ]);

        return $medicine->load('catalogItem');
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
