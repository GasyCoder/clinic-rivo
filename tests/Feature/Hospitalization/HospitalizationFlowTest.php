<?php

namespace Tests\Feature\Hospitalization;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationOrientationStatus;
use App\Enums\ConsultationOrientationType;
use App\Support\ConsultationWorkflow;
use App\Enums\ConsultationStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\MedicalDischarge;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-113 — le séjour hospitalier et sa fiche de régime.
 */
class HospitalizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_doctors_request_admits_the_patient_immediately(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);

        $this->requestHospitalization($doctor, $orientation);

        $stay = HospitalStay::query()->sole();
        $this->assertSame(HospitalStayStatus::Active, $stay->status);
        $this->assertSame('Médecine interne', $stay->service);
        $this->assertSame($doctor->id, $stay->admitted_by);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $stay->episodeOrientation->status);
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);

        $this->actingAs($doctor)->get('/hospitalisation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Hospitalization/Index')
                ->where('counts.active', 1)
                ->where('stays.data.0.uuid', $stay->uuid)
                ->where('stays.data.0.reason', 'Déshydratation sévère'));
    }

    public function test_closing_the_consultation_keeps_a_hospitalised_patient_out_of_settlement(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);

        $this->completeConsultation($orientation);

        // The patient is still in the ward: Réception cannot close the passage.
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);
    }

    public function test_medicine_and_soins_keep_the_diet_sheet_in_free_text(): void
    {
        $doctor = $this->doctor();
        $nurse = $this->nurse();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $stay = HospitalStay::query()->sole();

        // A blank line says nothing.
        $this->actingAs($nurse)->post("/hospitalisation/{$stay->uuid}/regime", [
            'served_on' => now()->toDateString(),
            'served_time' => '07:30',
        ])->assertSessionHasErrors('tea_bread');

        $this->actingAs($nurse)->post("/hospitalisation/{$stay->uuid}/regime", [
            'served_on' => now()->toDateString(),
            'served_time' => '07:30',
            'tea_bread' => 'Thé sucré + 1 pain',
            'observation' => 'A tout mangé',
        ])->assertSessionHasNoErrors();

        $entry = $stay->dietEntries()->sole();
        $this->assertSame('Thé sucré + 1 pain', $entry->tea_bread);
        $this->assertNull($entry->yogurt);
        $this->assertSame($nurse->id, $entry->recorded_by);

        // The doctor corrects it; the row is updated, never deleted.
        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}/regime/{$entry->uuid}", [
            'served_on' => now()->toDateString(),
            'served_time' => '07:45',
            'tea_bread' => 'Thé non sucré + 1 pain',
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('07:45', $entry->served_time);
        $this->assertSame('Thé non sucré + 1 pain', $entry->tea_bread);
        $this->assertNull($entry->observation);
        $this->assertSame($doctor->id, $entry->updated_by);
        $this->assertDatabaseCount('hospital_diet_entries', 1);

        $this->actingAs($nurse)->put("/hospitalisation/{$stay->uuid}", ['room_bed' => 'Chambre 3, lit B'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Chambre 3, lit B', $stay->fresh()->room_bed);
    }

    public function test_only_the_doctors_medical_discharge_ends_the_stay(): void
    {
        $doctor = $this->doctor();
        $nurse = $this->nurse();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();

        // ADR-156 — la sortie se prononce dans la visite, et termine le séjour.
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/visites");
        $visit = $episode->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)->sole();

        $this->actingAs($nurse)->post("/medicine/orientations/{$visit->uuid}/discharge", $this->discharge())
            ->assertForbidden();

        // A hospital stay is never a paraclinical-only visit: the final
        // diagnosis is required (ADR-094).
        $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/discharge", $this->discharge(['final_diagnosis' => '']))
            ->assertSessionHasErrors('final_diagnosis');

        $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/discharge", $this->discharge())
            ->assertSessionHasNoErrors();

        $stay->refresh();
        $this->assertSame(HospitalStayStatus::Discharged, $stay->status);
        $this->assertNull($stay->active_key);
        $this->assertSame(EpisodeOrientationStatus::Completed, $stay->episodeOrientation->status);

        $discharge = MedicalDischarge::query()->sole();
        $this->assertSame($stay->medical_discharge_id, $discharge->id);
        $this->assertSame('Gastro-entérite guérie', $discharge->final_diagnosis);

        // La visite reste ouverte : seule sa clôture termine la rencontre
        // (ADR-084), et c'est elle qui porte la sortie sur le passage.
        $this->completeConsultation($visit);
        $episode->refresh();
        $this->assertSame(EpisodeMedicalStatus::MedicallyDischarged, $episode->medical_status);
        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->administrative_status);

        // Nothing is added to a finished stay.
        $this->actingAs($nurse)->post("/hospitalisation/{$stay->uuid}/regime", [
            'served_on' => now()->toDateString(),
            'served_time' => '12:00',
            'puree' => 'Purée',
        ])->assertSessionHasErrors('diet');
    }

    public function test_a_death_pronounced_in_the_ward_leads_to_the_death_register(): void
    {
        $doctor = $this->doctor(['death_records.view']);
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        // La consultation qui a demandé l'hospitalisation a conclu par elle :
        // la sortie se prononce dans une visite du séjour (ADR-084, ADR-156).
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();

        $this->dischargeFromWardRound($stay, $doctor, [
            'type' => 'DECEASED',
            'patient_condition' => '',
            'discharge_prescription' => null,
            'recommendations' => null,
            'follow_up_at' => null,
            'death_occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'death_place' => 'Service de médecine',
            'death_causes' => 'Choc hypovolémique',
        ])->assertRedirect(route('deaths.index'));

        $this->assertSame(HospitalStayStatus::Discharged, $stay->fresh()->status);

        // Le statut médical du passage suit la clôture de la visite (ADR-084).
        $this->completeConsultation($episode->fresh()->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)->sole());
        $this->assertSame(EpisodeMedicalStatus::Deceased, $episode->fresh()->medical_status);
    }

    public function test_withdrawing_the_request_cancels_the_stay_until_the_diet_sheet_has_started(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'facility' => 'CHU Mahajanga',
            'reason' => 'Plateau technique',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        $stay = HospitalStay::query()->sole();
        $this->assertSame(HospitalStayStatus::Cancelled, $stay->status);
        $this->assertNull($stay->active_key);
        $this->assertSame(EpisodeOrientationStatus::Cancelled, $stay->episodeOrientation->status);
        $this->assertSame(EpisodeMedicalStatus::InCare, $episode->fresh()->medical_status);
        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")->assertNotFound();
    }

    public function test_the_request_cannot_be_withdrawn_once_the_diet_sheet_has_started(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $stay = HospitalStay::query()->sole();

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/regime", [
            'served_on' => now()->toDateString(),
            'served_time' => '07:30',
            'yogurt' => '1 yaourt',
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'facility' => 'CHU Mahajanga',
            'reason' => 'Plateau technique',
            'priority' => 'URGENT',
        ])->assertSessionHasErrors('orientation');

        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
    }

    public function test_the_diet_sheet_prints_with_what_the_file_already_knows(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $stay = HospitalStay::query()->sole();

        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}/regime/impression")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Hospitalization/DietSheetPrint')
                ->where('stay.request.reason', 'Déshydratation sévère')
                // Tobacco never asked here: unknown stays unknown, never « Non ».
                ->where('stay.smoker', null));
    }

    public function test_the_request_leaves_in_one_click_and_is_completed_in_the_module(): void
    {
        $doctor = $this->doctor();
        $nurse = $this->nurse();
        [, $orientation] = $this->consultation($doctor);

        // Only the priority: the details are completed in Hospitalisation.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();

        $stay = HospitalStay::query()->sole();
        $this->assertNull($stay->hospitalizationRequest->reason);

        $payload = [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'clinical_summary' => 'Pli cutané persistant',
            'planned_treatment' => 'Réhydratation IV',
            'priority' => 'URGENT',
            'instructions' => 'Surveillance de la diurèse',
        ];

        // The medical content belongs to the doctor.
        $this->actingAs($nurse)->put("/hospitalisation/{$stay->uuid}/demande", $payload)->assertForbidden();

        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}/demande", $payload)->assertSessionHasNoErrors();

        $request = $stay->hospitalizationRequest->fresh();
        $this->assertSame('Déshydratation sévère', $request->reason);
        $this->assertSame('Gastro-entérite aiguë', $request->admission_diagnosis);
        $this->assertSame('URGENT', $request->priority->value);

        // The service is completed alongside the room.
        $this->actingAs($nurse)->put("/hospitalisation/{$stay->uuid}", ['service' => 'Médecine interne', 'room_bed' => 'Ch. 2'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Médecine interne', $stay->fresh()->service);
    }

    // ── ADR-152 : le droit décide, jamais le rôle ────────────────────────

    /**
     * Exigence explicite du propriétaire : rien n'est fixé en dur. Un compte
     * de Réception à qui l'on accorde les droits fait **exactement** ce que
     * les droits disent — séjour, fiche de régime, diagnostic, visite et
     * sortie comprises. Ce test échoue si quiconque code un rôle en dur.
     */
    public function test_a_reception_account_granted_the_rights_does_everything_they_allow(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();

        // Le même socle qu'un médecin sur ce module, porté par RECEPTION.
        $agent = $this->userWith('RECEPTION', [
            'patients.view', 'hospitalization.view', 'hospitalization.update',
            'hospital_diet.record', 'diagnoses.view', 'diagnoses.create',
            'consultations.view', 'consultations.create', 'consultations.update',
            'medical_discharge.create', 'medical_record.view', 'prescriptions.view',
        ]);

        $props = $this->actingAs($agent)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        foreach (['can_update_stay', 'can_add_diet', 'can_add_diagnosis', 'can_open_visit', 'can_discharge'] as $capability) {
            $this->assertTrue($props['capabilities'][$capability], "{$capability} doit suivre le droit accordé, jamais le rôle.");
        }

        $this->actingAs($agent)->put("/hospitalisation/{$stay->uuid}", ['room_bed' => 'Ch. 4'])->assertSessionHasNoErrors();
        $this->actingAs($agent)->post("/hospitalisation/{$stay->uuid}/regime", [
            'served_on' => now()->toDateString(), 'served_time' => '08:00', 'tea_bread' => 'Thé',
        ])->assertSessionHasNoErrors();
        $this->actingAs($agent)->post("/hospitalisation/{$stay->uuid}/diagnostics", ['description' => 'Apyrexie'])->assertSessionHasNoErrors();
        $this->actingAs($agent)->post("/hospitalisation/{$stay->uuid}/visites")->assertRedirect();

        // ADR-156 — la sortie se prononce dans la visite, et termine le séjour.
        $visit = $stay->episode->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)->sole();
        $this->actingAs($agent)->post("/medicine/orientations/{$visit->uuid}/discharge", $this->discharge())
            ->assertSessionHasNoErrors();

        $this->assertSame(HospitalStayStatus::Discharged, $stay->fresh()->status);
        $this->assertSame('Ch. 4', $stay->fresh()->room_bed);
    }

    // ── ADR-156 : une seule sortie, prononcée dans la visite ─────────────

    /**
     * Le défaut signalé : deux endroits pour un seul acte. La sortie prononcée
     * depuis la page du séjour laissait la visite « En cours » — orientation
     * Médecine active, passage jamais en attente de règlement. Elle se
     * prononce désormais là où le médecin travaille, et termine le séjour.
     */
    public function test_a_discharge_from_a_ward_round_ends_the_stay(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();

        $this->dischargeFromWardRound($stay, $doctor)->assertSessionHasNoErrors();

        $stay->refresh();
        $this->assertSame(HospitalStayStatus::Discharged, $stay->status);
        $this->assertNull($stay->active_key);
        $this->assertSame(MedicalDischarge::query()->sole()->id, $stay->medical_discharge_id);
        // Le lit est rendu : jamais un passage sorti dont le séjour reste actif.
        $this->assertSame(EpisodeOrientationStatus::Completed, $stay->episodeOrientation->status);
        // La visite se clôture ensuite normalement : la sortie prononcée EST
        // sa conduite à tenir transmise (ADR-084, ADR-107).
        $visit = $episode->fresh()->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)->sole();
        $this->completeConsultation($visit);

        $this->assertSame(ConsultationStatus::Completed, $visit->consultation()->firstOrFail()->status);
        // Le statut médical et l'attente de règlement suivent la clôture (ADR-084).
        $this->assertSame(EpisodeMedicalStatus::MedicallyDischarged, $episode->fresh()->medical_status);
        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->fresh()->administrative_status);
    }

    /**
     * Le module Hospitalisation ne liste plus les séjours terminés : ils ne
     * doivent pas se perdre. Toutes les sorties se suivent à la Réception, et
     * un passage passé par un lit le dit — avec le lien vers son séjour pour
     * qui peut l'ouvrir.
     */
    public function test_the_settlement_queue_shows_the_passage_went_through_a_bed(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->dischargeFromWardRound($stay, $doctor)->assertSessionHasNoErrors();
        $this->completeConsultation($episode->fresh()->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)->sole());

        $agent = $this->userWith('RECEPTION', [
            'patients.view', 'episodes.settlement.view', 'hospitalization.view',
        ]);
        $row = collect($this->actingAs($agent)->get('/reception/sorties')
            ->viewData('page')['props']['episodes']['data'])
            ->firstWhere('uuid', $episode->uuid);

        $this->assertNotNull($row, 'Le passage sorti du lit doit figurer dans la file de règlement.');
        $this->assertNotNull($row['stay']);
        $this->assertFalse($row['stay']['is_active']);
        $this->assertSame($stay->uuid, $row['stay']['uuid']);

        // Sans le droit d'ouvrir un séjour, le fait reste dit — le lien, non.
        $clerk = $this->userWith('RECEPTION2', ['patients.view', 'episodes.settlement.view']);
        $row = collect($this->actingAs($clerk)->get('/reception/sorties')
            ->viewData('page')['props']['episodes']['data'])
            ->firstWhere('uuid', $episode->uuid);

        $this->assertNotNull($row['stay']);
        $this->assertNull($row['stay']['uuid']);
    }

    /**
     * Le patient sorti du lit dont le médecin n'a pas encore clôturé n'est pas
     * réglable (ADR-054/084) — mais il ne doit pas disparaître de la Réception
     * pour autant : il est dans « Sortie médicale prononcée », avec la raison.
     */
    public function test_a_medically_discharged_passage_stays_visible_before_its_closure(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->dischargeFromWardRound($stay, $doctor)->assertSessionHasNoErrors();

        $agent = $this->userWith('RECEPTION', [
            'patients.view', 'episodes.settlement.view', 'hospitalization.view',
        ]);

        // La visite est encore ouverte : rien à régler, et rien de perdu.
        $props = $this->actingAs($agent)->get('/reception/sorties?tab=pending')->viewData('page')['props'];
        $this->assertNull(collect($props['episodes']['data'])->firstWhere('uuid', $episode->uuid));
        $this->assertSame(1, $props['counts']['in_care']);

        $props = $this->actingAs($agent)->get('/reception/sorties?tab=in_care')->viewData('page')['props'];
        $this->assertNotNull(collect($props['episodes']['data'])->firstWhere('uuid', $episode->uuid));

        // Clôturée, il rejoint « À régler » et quitte cette vue.
        $this->completeConsultation($episode->fresh()->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)->sole());

        $props = $this->actingAs($agent)->get('/reception/sorties?tab=pending')->viewData('page')['props'];
        $this->assertNotNull(collect($props['episodes']['data'])->firstWhere('uuid', $episode->uuid));
        $this->assertSame(0, $props['counts']['in_care']);
    }

    /**
     * Une sortie déjà prononcée EST la conduite à tenir de la rencontre : la
     * clôture la rattache, elle ne la redemande pas par un clic au-dessus d'un
     * fait daté et signé (ADR-107, ADR-156).
     */
    public function test_a_pronounced_discharge_closes_the_encounter_without_reselecting_it(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->dischargeFromWardRound($stay, $doctor)->assertSessionHasNoErrors();

        // L'état hérité : une seconde rencontre ouverte sans conduite à tenir,
        // sur un passage dont la sortie est déjà prononcée.
        $visit = app(CreateEpisodeOrientationAction::class)->execute(
            $episode->fresh(), CatalogModule::Hospitalization, CatalogModule::Medicine, $doctor, 'Visite de service.',
        );
        $visit = app(AcceptMedicineOrientationAction::class)->execute($visit, $doctor);
        $consultation = $visit->consultation()->firstOrFail();

        // Plus aucun obstacle de conduite à tenir : elle est déjà prononcée.
        // Les étapes du parcours, elles, restent à résoudre comme d'habitude.
        $blockers = app(ConsultationWorkflow::class)->closureBlockerMessages($consultation);
        $this->assertEmpty(array_filter($blockers, fn (string $m): bool => str_contains($m, 'Conduite à tenir')));

        $this->completeConsultation($visit);

        $consultation->refresh();
        $this->assertSame(ConsultationStatus::Completed, $consultation->status);
        // La sortie est rattachée comme conduite à tenir, jamais fabriquée.
        $orientationRow = $consultation->orientations()->whereNull('cancelled_at')->sole();
        $this->assertSame(ConsultationOrientationType::Discharge, $orientationRow->type);
        $this->assertSame(ConsultationOrientationStatus::Submitted, $orientationRow->status);
        $this->assertSame($episode->fresh()->medicalDischarge->id, $orientationRow->medical_discharge_id);
    }

    /** Le séjour n'a plus de formulaire de sortie à lui : un seul acte. */
    public function test_the_stay_has_no_discharge_form_of_its_own(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $stay = HospitalStay::query()->sole();

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/sortie", $this->discharge())
            ->assertNotFound();
    }

    /**
     * Le séjour déjà bloqué avant l'ADR-155 : sortie prononcée, une visite
     * restée ouverte à côté. Elle était inclôturable — « Sortie médicale »
     * restait « à transmettre », la sortie n'étant cherchée que sur sa propre
     * consultation. La sortie du **passage** est désormais le fait transmis
     * (ADR-107), ce qui rouvre ces dossiers hérités.
     */
    public function test_a_ward_round_left_open_by_a_pronounced_discharge_can_still_close(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->dischargeFromWardRound($stay, $doctor)->assertSessionHasNoErrors();

        // L'état hérité, reconstruit par les vraies actions : une seconde
        // visite laissée ouverte à côté d'une sortie déjà prononcée.
        $visit = app(CreateEpisodeOrientationAction::class)->execute(
            $episode->fresh(), CatalogModule::Hospitalization, CatalogModule::Medicine, $doctor, 'Visite de service.',
        );
        $visit = app(AcceptMedicineOrientationAction::class)->execute($visit, $doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/orientation", [
            'type' => 'DISCHARGE',
        ])->assertSessionHasNoErrors();

        // La sortie déjà prononcée EST la demande transmise (ADR-107).
        $this->assertSame(
            ConsultationOrientationStatus::Submitted,
            $visit->consultation()->firstOrFail()->orientations()->sole()->status,
        );

        $this->completeConsultation($visit);

        $this->assertSame(ConsultationStatus::Completed, $visit->consultation()->firstOrFail()->status);
        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->fresh()->administrative_status);
    }

    // ── ADR-148 : la visite de service ───────────────────────────────────

    /**
     * Un patient hospitalisé continue d'être examiné et prescrit. La visite est
     * une vraie consultation : l'assistant Médecine existant la porte, et aucun
     * circuit n'est dupliqué.
     */
    public function test_a_ward_round_opens_a_real_consultation_on_the_stay(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/visites")->assertRedirect();

        $visit = $episode->fresh()->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->where('source_module', CatalogModule::Hospitalization->value)
            ->sole();

        // Prise en charge d'emblée : le patient est dans un lit, jamais « à prendre ».
        $this->assertSame(EpisodeOrientationStatus::InProgress, $visit->status);
        $this->assertSame($doctor->id, $visit->accepted_by);
        $this->assertNotNull($visit->consultation()->first());

        // Il reste hospitalisé : une visite ne le fait pas descendre du lit.
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);
    }

    /** Une visite déjà ouverte est retrouvée, jamais doublée (`active_key`). */
    public function test_opening_a_second_ward_round_reuses_the_open_one(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/visites");
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/visites");

        $this->assertSame(1, $episode->fresh()->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)
            ->count());
    }

    /** La page liste ce que le séjour a produit, et le droit qui l'ouvre. */
    public function test_the_stay_lists_its_ward_rounds(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/visites");

        $props = $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];

        $this->assertCount(1, $props['visits']);
        $this->assertTrue($props['visits'][0]['is_open']);
        $this->assertTrue($props['capabilities']['can_open_visit']);

        // La Réception lit le séjour, elle n'ouvre aucune visite (ADR-147).
        $agent = $this->userWith('RECEPTION', ['patients.view', 'hospitalization.view']);
        $agentProps = $this->actingAs($agent)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertFalse($agentProps['capabilities']['can_open_visit']);
        $this->assertSame([], $agentProps['visits']);
        $this->actingAs($agent)->post("/hospitalisation/{$stay->uuid}/visites")->assertForbidden();
    }

    /** Un séjour terminé n'ouvre plus de visite : sa sortie est prononcée. */
    public function test_a_finished_stay_opens_no_ward_round(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->dischargeFromWardRound($stay, $doctor)->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->post("/hospitalisation/{$stay->uuid}/visites")
            ->assertSessionHasErrors('visit');
    }

    /**
     * Le cul-de-sac constaté : une visite atteignait « Décision & clôture » et
     * aucune des six conduites ne convenait — « Sortie médicale » et
     * « Hospitalisation » sont fausses pour un patient déjà au lit —, alors que
     * la clôture en exige une transmise (ADR-084).
     */
    public function test_a_ward_round_offers_only_the_conduites_that_make_sense_in_a_bed(): void
    {
        $doctor = $this->doctor(['pediatrics.request', 'surgery.request', 'maternity.request']);
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/visites");
        $visit = $stay->episode->orientations()
            ->where('source_module', CatalogModule::Hospitalization->value)->sole();

        $props = $this->actingAs($doctor)->get("/medicine/orientations/{$visit->uuid}/cloture")->viewData('page')['props'];
        $types = collect($props['options']['orientation_types'])->pluck('value')->all();

        // Ouvrir un second séjour, ou sortir sans terminer le séjour : jamais.
        // « Hospitalisation » ouvrirait un second séjour sur le même passage.
        $this->assertNotContains('HOSPITALIZATION', $types);
        // ADR-156 — « Sortie médicale » est proposée : elle termine le séjour.
        $this->assertContains('DISCHARGE', $types);
        $this->assertContains('CONTINUED_HOSPITALIZATION', $types);

        // Et l'écran sait dire que le patient est dans un lit.
        $this->assertNotNull($props['hospital_stay']);
        $this->assertSame("/hospitalisation/{$stay->uuid}", $props['hospital_stay']['url']);
    }

    /**
     * « Poursuite de l'hospitalisation » ne demande rien à personne : le séjour
     * est déjà ouvert. Elle est donc transmise au moment du choix, et la visite
     * se clôture — sans toucher au séjour ni au lit.
     */
    public function test_continuing_the_stay_closes_the_ward_round_without_ending_the_stay(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/visites");
        $visit = $episode->orientations()->where('source_module', CatalogModule::Hospitalization->value)->sole();

        $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/orientation", [
            'type' => 'CONTINUED_HOSPITALIZATION',
        ])->assertSessionHasNoErrors();

        // Transmise d'emblée : il n'y a aucun formulaire à envoyer.
        $consultation = $visit->consultation()->firstOrFail();
        $this->assertSame('SUBMITTED', $consultation->orientations()->sole()->status->value);

        // Aucune orientation nouvelle : le patient n'est transmis à personne.
        // Celle de son admission reste la seule — un second séjour serait
        // exactement ce que « Hospitalisation » aurait produit ici.
        $this->assertSame(1, $episode->orientations()
            ->where('destination_module', CatalogModule::Hospitalization->value)
            ->count());

        $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/diagnoses", [
            'type' => 'FINAL', 'description' => 'Évolution favorable',
        ])->assertSessionHasNoErrors();
        foreach ([['step' => 'dossier'], ['step' => 'consultation'], ['step' => 'examen']] as $payload) {
            $this->actingAs($doctor)->put("/medicine/orientations/{$visit->uuid}/interrogatoire", [
                'chief_complaint' => 'Visite du jour', 'reason' => '<p>Apyrétique</p>', 'current_treatments' => [],
            ]);
            $this->actingAs($doctor)->put("/medicine/orientations/{$visit->uuid}/examen-clinique", [
                'clinical_exam' => '<p>Abdomen souple</p>',
            ]);
            $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/steps", $payload + ['intent' => 'COMPLETE']);
        }
        foreach ([['step' => 'paraclinique'], ['step' => 'ordonnance']] as $payload) {
            $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/steps", $payload + ['intent' => 'SKIP']);
        }

        $this->actingAs($doctor)->post("/medicine/orientations/{$visit->uuid}/complete")->assertSessionHasNoErrors();

        // La visite est close, le patient toujours dans son lit.
        $this->assertSame(EpisodeOrientationStatus::Completed, $visit->fresh()->status);
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);
    }

    // ── ADR-147 : diagnostic de sortie et lecture par la Réception ───────

    /**
     * Le défaut signalé : la sortie était impossible. Le passage avait ses
     * diagnostics, la page ne les servait pas au formulaire — « Aucun
     * diagnostic posé », et le bouton grisé.
     */
    public function test_the_discharge_form_receives_the_diagnoses_already_recorded(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();

        $props = $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];

        $this->assertSame(['Gastro-entérite aiguë'], collect($props['diagnoses'])->pluck('description')->all());
        $this->assertSame('CONSULTATION', $props['diagnoses'][0]['origin']);
        $this->assertTrue($props['capabilities']['can_add_diagnosis']);
    }

    /**
     * La consultation est close : l'ADR-076 refuse toute écriture ordinaire.
     * Le diagnostic conclu au terme du séjour vit donc sur le séjour.
     */
    public function test_a_discharge_diagnosis_is_recorded_on_the_stay_never_on_the_closed_consultation(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $consultation = $orientation->consultation()->firstOrFail();
        $before = $consultation->diagnoses()->count();

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/diagnostics", [
            'description' => 'Déshydratation corrigée',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $consultation->fresh()->diagnoses()->count());
        $this->assertSame('Déshydratation corrigée', $stay->fresh()->diagnoses()->sole()->description);

        // Les deux sources sont servies au formulaire, dans l'ordre du dossier.
        $props = $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertSame(['CONSULTATION', 'STAY'], collect($props['diagnoses'])->pluck('origin')->all());
    }

    /** Append-only, comme tout diagnostic (ADR-035). */
    public function test_a_discharge_diagnosis_is_never_rewritten_nor_removed(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $stay = HospitalStay::query()->sole();
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/diagnostics", ['description' => 'Anémie']);
        $diagnosis = $stay->diagnoses()->sole();

        $this->expectException(\RuntimeException::class);
        $diagnosis->update(['description' => 'Autre chose']);
    }

    /** Un séjour terminé a déjà sa conclusion signée. */
    public function test_a_finished_stay_accepts_no_new_diagnosis(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $this->completeConsultation($orientation);
        $stay = HospitalStay::query()->sole();
        $this->dischargeFromWardRound($stay, $doctor)->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->post("/hospitalisation/{$stay->uuid}/diagnostics", ['description' => 'Trop tard'])
            ->assertForbidden();
    }

    /**
     * La Réception lit le module — détail, dossier, impression — et n'y écrit
     * rien : ni séjour, ni fiche de régime, ni diagnostic.
     */
    public function test_the_reception_reads_the_stay_and_writes_nothing(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->requestHospitalization($doctor, $orientation);
        $stay = HospitalStay::query()->sole();
        $agent = $this->userWith('RECEPTION', ['patients.view', 'hospitalization.view']);

        $props = $this->actingAs($agent)->get("/hospitalisation/{$stay->uuid}")->viewData('page')['props'];
        $this->assertFalse($props['capabilities']['can_discharge']);
        $this->assertFalse($props['capabilities']['can_update_stay']);
        $this->assertFalse($props['capabilities']['can_add_diet']);
        $this->assertFalse($props['capabilities']['can_edit_request']);
        $this->assertFalse($props['capabilities']['can_add_diagnosis']);
        // Rien de clinique n'est servi sans le droit qui le possède.
        $this->assertSame([], $props['diagnoses']);

        $this->actingAs($agent)->get("/hospitalisation/{$stay->uuid}/regime/impression")->assertOk();
        $this->actingAs($agent)->put("/hospitalisation/{$stay->uuid}", ['room_bed' => '12'])->assertForbidden();
        $this->actingAs($agent)->post("/hospitalisation/{$stay->uuid}/regime", ['served_on' => now()->toDateString(), 'tea_bread' => 'Thé'])->assertForbidden();
        $this->actingAs($agent)->post("/hospitalisation/{$stay->uuid}/diagnostics", ['description' => 'X'])->assertForbidden();
    }

    private function requestHospitalization(User $doctor, EpisodeOrientation $orientation): void
    {
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();
    }

    /**
     * ADR-156 — la sortie se prononce dans une visite de service, et termine
     * le séjour. Le séjour n'a plus de formulaire de sortie à lui.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function dischargeFromWardRound(HospitalStay $stay, User $actor, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($actor)->post("/hospitalisation/{$stay->uuid}/visites");

        // `active_key` : une consultation Médecine déjà ouverte est reprise
        // plutôt que doublée — la visite est alors celle-là (ADR-148).
        $visit = $stay->episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->where('status', EpisodeOrientationStatus::InProgress->value)
            ->latest('id')
            ->firstOrFail();

        return $this->actingAs($actor)
            ->post("/medicine/orientations/{$visit->uuid}/discharge", $this->discharge($overrides));
    }

    /**
     * Clôture une visite de service par son vrai chemin : la conduite à tenir
     * d'un patient au lit est « Poursuite de l'hospitalisation » (ADR-149).
     */
    private function closeWardRound(EpisodeOrientation $orientation): void
    {
        $doctor = User::query()->findOrFail($orientation->consultation()->firstOrFail()->doctor_id);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/orientation", [
            'type' => 'CONTINUED_HOSPITALIZATION',
        ])->assertSessionHasNoErrors();

        $this->completeConsultation($orientation);
    }

    /** Closes the consultation through the real closure step. */
    private function completeConsultation(EpisodeOrientation $orientation): void
    {
        $doctor = User::query()->findOrFail($orientation->consultation()->firstOrFail()->doctor_id);

        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Vomissements',
            'reason' => '<p>Vomissements depuis 2 jours</p>',
            'current_treatments' => [],
        ]);
        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => '<p>Pli cutané persistant</p>',
        ]);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Gastro-entérite aiguë',
        ]);

        foreach ([
            ['step' => 'dossier', 'intent' => 'COMPLETE'],
            ['step' => 'consultation', 'intent' => 'COMPLETE'],
            ['step' => 'examen', 'intent' => 'COMPLETE'],
            ['step' => 'paraclinique', 'intent' => 'SKIP'],
            ['step' => 'ordonnance', 'intent' => 'SKIP'],
        ] as $payload) {
            $this->actingAs($doctor)
                ->post("/medicine/orientations/{$orientation->uuid}/steps", $payload)
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasNoErrors();

        $this->assertSame(EpisodeOrientationStatus::Completed, $orientation->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function discharge(array $overrides = []): array
    {
        return array_merge([
            'type' => 'NORMAL',
            'final_diagnosis' => 'Gastro-entérite guérie',
            'patient_condition' => 'Guéri',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i'),
        ], $overrides);
    }

    /** @param array<int, string> $extra */
    private function doctor(array $extra = []): User
    {
        return $this->userWith('MEDICINE', [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view',
            'diagnoses.view', 'diagnoses.create', 'prescriptions.view',
            'hospitalization.request', 'transfer.request', 'medical_discharge.create',
            'hospitalization.view', 'hospitalization.update', 'hospital_diet.record',
            ...$extra,
        ]);
    }

    private function nurse(): User
    {
        return $this->userWith('NURSE', [
            'care.view', 'hospitalization.view', 'hospitalization.update', 'hospital_diet.record',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWith(string $roleCode, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function consultation(User $doctor): array
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $item = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
            'name' => 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $item->uuid,
            'quantity' => 1,
        ]], $doctor);
        $medicine = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($medicine, $doctor);

        return [$episode->fresh(), $medicine->fresh()];
    }
}
