<?php

namespace Tests\Feature\Maternity;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\AppointmentStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MaternityEncounterType;
use App\Enums\PregnancyStatus;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeServiceRequest;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Pregnancy;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ADR-204 — deux parcours Maternité liés à la même grossesse : la
 * consultation prénatale et l'accouchement.
 *
 *   enregistrer ≠ terminer        l'enregistrement automatique écrit le dossier ;
 *                                 seule la finalisation le clôt
 *   Appointment ≠ Episode         un rendez-vous n'ouvre aucun passage
 *   un examen demandé en Maternité appartient à son dossier (maternity_record_id)
 *   et ses résultats restent ceux du Laboratoire et de l'Imagerie
 */
class MaternityEncounterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ProfessionalProfileSeeder::class, RolePermissionSeeder::class]);
    }

    // ── Le parcours ───────────────────────────────────────────────────────

    public function test_the_encounter_type_is_chosen_explicitly_and_never_finalizes_anything(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->passage($this->patient(), $midwife, '2026-09-24 08:00:00');

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('record', null)
                ->where('encounter.effective', null)
                ->has('encounterOptions', 2));

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/parcours", ['encounter_type' => 'NEITHER'])
            ->assertSessionHasErrors('encounter_type');
        $this->assertSame(0, MaternityRecord::query()->count());

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/parcours", ['encounter_type' => 'PRENATAL'])
            ->assertSessionHasNoErrors();

        $record = MaternityRecord::query()->sole();
        $this->assertSame(MaternityEncounterType::Prenatal, $record->encounter_type);
        $this->assertNull($record->completed_at);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);

        // Changer de parcours n'efface rien : seules les étapes changent.
        $record->forceFill(['obstetric_context' => 'Contexte déjà saisi.'])->save();
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/parcours", ['encounter_type' => 'DELIVERY'])
            ->assertSessionHasNoErrors();
        $this->assertSame(MaternityEncounterType::Delivery, $record->fresh()->encounter_type);
        $this->assertSame('Contexte déjà saisi.', $record->fresh()->obstetric_context);
    }

    public function test_autosave_writes_the_structured_consultation_without_finishing_it(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->prenatal($this->patient(), $midwife, '2026-09-24 08:00:00');

        $prenatal = [
            'visit_reason' => 'COMPLAINT',
            'visit_reason_details' => 'Douleurs pelviennes',
            'reported_since_last' => ['SYMPTOMS', 'CONTRACTIONS'],
            'fundal_height_cm' => 24,
            'fetal_heart_rate' => 142,
            'fetal_movements' => 'PRESENT',
            'contractions' => 'NO',
            'presentation' => 'CEPHALIC',
            'clinical_summary' => 'Grossesse évolutive.',
            'watch_points' => 'Surveiller la tension.',
            'plan' => 'Bilan du 2e trimestre.',
        ];
        $this->save($midwife, $orientation, [
            'pregnancy_choice' => 'CREATE',
            'pregnancy_data' => ['last_menstrual_period' => '2026-03-01', 'gravidity' => 2, 'parity' => 1],
            'prenatal_data' => $prenatal,
        ])->assertSessionHasNoErrors();

        // Deux enregistrements de suite — l'écran renvoie tout le formulaire : le second ne duplique rien.
        $this->save($midwife, $orientation, ['prenatal_data' => [...$prenatal, 'plan' => 'Bilan du 2e trimestre, revoir dans un mois.']])
            ->assertSessionHasNoErrors();

        $record = MaternityRecord::query()->sole();
        $this->assertSame('COMPLAINT', $record->prenatal_data['visit_reason']);
        $this->assertSame(['SYMPTOMS', 'CONTRACTIONS'], $record->prenatal_data['reported_since_last'] ?? null);
        $this->assertSame('Bilan du 2e trimestre, revoir dans un mois.', $record->prenatal_data['plan']);
        $this->assertNull($record->completed_at, 'Un enregistrement n’est jamais une finalisation.');
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
        $this->assertSame(1, Pregnancy::query()->count());
    }

    public function test_unknown_structured_values_are_refused(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->prenatal($this->patient(), $midwife, '2026-09-24 08:00:00');

        $this->save($midwife, $orientation, [
            'pregnancy_choice' => 'CREATE',
            'prenatal_data' => ['visit_reason' => 'INVENTED', 'reported_since_last' => ['NOPE'], 'presentation' => 'SIDEWAYS'],
        ])->assertSessionHasErrors(['prenatal_data.visit_reason', 'prenatal_data.reported_since_last.0', 'prenatal_data.presentation']);
    }

    public function test_a_late_autosave_never_writes_into_a_finalized_record(): void
    {
        $midwife = $this->midwife();
        [$episode, $orientation] = $this->prenatal($this->patient(), $midwife, '2026-09-24 08:00:00');
        $this->save($midwife, $orientation, ['pregnancy_choice' => 'CREATE', 'pregnancy_data' => ['last_menstrual_period' => '2026-03-01']])
            ->assertSessionHasNoErrors();
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();

        $record = MaternityRecord::query()->sole();
        $this->assertNotNull($record->completed_at);

        // La même prise en charge terminée : refusée avant toute écriture.
        $this->save($midwife, $orientation, ['obstetric_context' => 'Arrivé trop tard.'])->assertForbidden();

        // Même si une nouvelle orientation Maternité s'ouvre sur ce passage, le dossier terminé reste clos.
        $again = app(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Medicine, CatalogModule::Maternity, $midwife, 'Revoir');
        $this->actingAs($midwife)->post("/maternity/orientations/{$again->uuid}/accept")->assertRedirect();
        $this->save($midwife, $again->fresh(), ['obstetric_context' => 'Arrivé trop tard.'])
            ->assertSessionHasErrors('maternity_record');

        $this->assertNull($record->fresh()->obstetric_context);
    }

    public function test_finishing_a_typed_encounter_requires_the_pregnancy_choice(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->prenatal($this->patient(), $midwife, '2026-09-24 08:00:00');

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasErrors('pregnancy_choice');

        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
        $this->assertNull(MaternityRecord::query()->sole()->completed_at);
    }

    // ── Rendez-vous ──────────────────────────────────────────────────────

    public function test_the_next_appointment_is_optional_and_never_opens_a_passage(): void
    {
        Carbon::setTestNow('2026-09-24 09:00:00');
        $midwife = $this->midwife();
        $patient = $this->patient();
        [, $orientation] = $this->prenatal($patient, $midwife, '2026-09-24 08:00:00');
        $this->save($midwife, $orientation, [
            'pregnancy_choice' => 'CREATE',
            'pregnancy_data' => ['last_menstrual_period' => '2026-03-01'],
            'prenatal_data' => ['next_appointment' => ['enabled' => true, 'scheduled_at' => '2026-10-24T09:30', 'reason' => '', 'notes' => 'À jeun']],
        ])->assertSessionHasNoErrors();
        $episodes = Episode::query()->count();

        $this->assertSame(0, Appointment::query()->count(), 'Le rendez-vous n’est créé qu’à la fin de la consultation.');

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();

        $appointment = Appointment::query()->sole();
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame('2026-10-24 09:30:00', $appointment->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('Suivi prénatal', $appointment->reason);
        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertSame(Pregnancy::query()->sole()->id, $appointment->pregnancy_id);
        $this->assertSame($episodes, Episode::query()->count(), 'Appointment ≠ Episode : aucun passage ouvert.');

        // Le prochain passage de la même grossesse retrouve le rendez-vous.
        [, $next] = $this->prenatal($patient, $midwife, '2026-10-24 09:40:00');
        $this->actingAs($midwife)->get("/maternity/orientations/{$next->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('upcomingAppointments', 1)
                ->where('upcomingAppointments.0.uuid', $appointment->uuid));
        Carbon::setTestNow();
    }

    public function test_no_appointment_is_created_when_none_is_planned_and_a_past_date_is_refused(): void
    {
        Carbon::setTestNow('2026-09-24 09:00:00');
        $midwife = $this->midwife();
        [, $orientation] = $this->prenatal($this->patient(), $midwife, '2026-09-24 08:00:00');
        $this->save($midwife, $orientation, [
            'pregnancy_choice' => 'CREATE',
            'prenatal_data' => ['next_appointment' => ['enabled' => true, 'scheduled_at' => '2026-09-01T09:00']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasErrors('prenatal_data.next_appointment.scheduled_at');
        $this->assertNull(MaternityRecord::query()->sole()->completed_at);

        $this->save($midwife, $orientation, ['prenatal_data' => ['next_appointment' => ['enabled' => false]]])->assertSessionHasNoErrors();
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();
        $this->assertSame(0, Appointment::query()->count());
        Carbon::setTestNow();
    }

    // ── Paraclinique ─────────────────────────────────────────────────────

    public function test_lab_and_imaging_requests_belong_to_the_maternity_record(): void
    {
        $midwife = $this->midwife(withExams: true);
        [$episode, $orientation] = $this->linkedPrenatal($this->patient(), $midwife, '2026-09-24 08:00:00');
        // La facturation à la demande lit le contexte financier du passage (ADR-051, ADR-054).
        $episode->update(['financial_mode' => 'SELF', 'financial_context_completed_at' => now(), 'financial_context_completed_by' => $midwife->id]);
        $analysis = $this->service($midwife, 'LAB-NFS', 'NFS', CatalogModule::Laboratory);
        $this->tariff($midwife, $analysis, '12000.00');
        $echo = $this->service($midwife, 'ECHO-OBS-T3', 'Échographie obstétricale T3', CatalogModule::Imaging);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertSessionHasNoErrors();
        // Un double clic ne produit pas une seconde demande.
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertSessionHasErrors('lab_request');

        $record = MaternityRecord::query()->sole();
        $lab = LabRequest::query()->sole();
        $this->assertSame($record->id, $lab->maternity_record_id);
        $this->assertNull($lab->consultation_id, 'La consultation Médecine n’est pas détournée.');
        $this->assertNotNull($lab->items->sole()->billable_item_id, 'Facturé à la demande, comme en consultation.');
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'source_module' => CatalogModule::Maternity->value,
            'destination_module' => CatalogModule::Laboratory->value,
        ]);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/imagerie", [
            'items' => [['catalog_item_uuid' => $echo->uuid]],
        ])->assertSessionHasNoErrors();
        $imaging = ImagingRequest::query()->sole();
        $this->assertSame($record->id, $imaging->maternity_record_id);

        // Le compte rendu se saisit par la même fenêtre, adressée à la prise en charge Maternité.
        $item = $imaging->items->sole();
        $this->actingAs($midwife)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests/{$item->uuid}/result", [
            'result_value' => '<p>Présentation céphalique, biométrie au 50e percentile.</p>',
        ])->assertSessionHasNoErrors();

        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('labRequests', 1)
                ->has('imagingRequests', 1)
                ->where('paraclinicalHistory.counts.lab', 1)
                ->where('paraclinicalHistory.counts.imaging', 1)
                ->where('paraclinicalHistory.counts.pending', 1));
    }

    public function test_pending_results_never_block_the_end_of_the_consultation(): void
    {
        $midwife = $this->midwife(withExams: true);
        [, $orientation] = $this->linkedPrenatal($this->patient(), $midwife, '2026-09-24 08:00:00');
        $analysis = $this->service($midwife, 'LAB-GLYC', 'Glycémie', CatalogModule::Laboratory);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertSessionHasNoErrors();
        $this->assertNull(LabRequest::query()->sole()->items->sole()->resulted_at);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();
        $this->assertNotNull(MaternityRecord::query()->sole()->completed_at);

        // Le résultat rendu plus tard par le Laboratoire se lit dans le suivi — la Maternité n'en garde aucune copie.
        LabRequest::query()->sole()->items->sole()->forceFill(['result_value' => '0,92 g/L', 'resulted_at' => now()])->save();
        $this->actingAs($midwife)->get("/maternity/orientations/{$orientation->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('paraclinicalHistory.counts.pending', 0)
                ->where('paraclinicalHistory.groups.0.entries.0.status', 'DONE')
                ->where('paraclinicalHistory.groups.0.entries.0.result', '0,92 g/L'));
    }

    public function test_a_request_without_result_is_withdrawn_from_maternity(): void
    {
        $midwife = $this->midwife(withExams: true);
        [, $orientation] = $this->linkedPrenatal($this->patient(), $midwife, '2026-09-24 08:00:00');
        $analysis = $this->service($midwife, 'LAB-NFS', 'NFS', CatalogModule::Laboratory);
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertSessionHasNoErrors();
        $lab = LabRequest::query()->sole();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/analyses/{$lab->uuid}/retirer", ['reason' => 'Demandée par erreur'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($lab->fresh()->cancelled_at);
    }

    public function test_exam_requests_need_the_exam_permission_not_only_maternity(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->linkedPrenatal($this->patient(), $midwife, '2026-09-24 08:00:00');
        $analysis = $this->service($midwife, 'LAB-NFS', 'NFS', CatalogModule::Laboratory);

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertForbidden();
        $this->assertSame(0, LabRequest::query()->count());
    }

    public function test_the_paraclinical_history_follows_the_pregnancy_across_consultations(): void
    {
        $midwife = $this->midwife(withExams: true);
        $patient = $this->patient();
        [, $first] = $this->linkedPrenatal($patient, $midwife, '2026-06-01 08:00:00');
        $analysis = $this->service($midwife, 'LAB-GROUP-RH', 'Groupe sanguin et rhésus', CatalogModule::Laboratory);
        $this->actingAs($midwife)->post("/maternity/orientations/{$first->uuid}/analyses", [
            'items' => [['catalog_item_uuid' => $analysis->uuid]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($midwife)->post("/maternity/orientations/{$first->uuid}/complete")->assertSessionHasNoErrors();
        $pregnancy = Pregnancy::query()->sole();

        [, $second] = $this->prenatal($patient, $midwife, '2026-07-01 08:00:00');
        $this->save($midwife, $second, ['pregnancy_choice' => 'CONTINUE', 'pregnancy_uuid' => $pregnancy->uuid])->assertSessionHasNoErrors();

        $this->actingAs($midwife)->get("/maternity/orientations/{$second->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('paraclinicalHistory.groups', 2)
                ->where('paraclinicalHistory.groups.0.entries.0.exam', 'Groupe sanguin et rhésus')
                ->where('paraclinicalHistory.groups.1.is_current', true)
                ->has('prenatalAdvice')
                ->where('prenatalAdvice.validated', false));
    }

    // ── Accouchement ─────────────────────────────────────────────────────

    public function test_the_delivery_closes_the_pregnancy_at_the_recorded_time_and_twins_stay_one_pregnancy(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        $midwife = $this->midwife();
        $patient = $this->patient();
        [, $prenatal] = $this->linkedPrenatal($patient, $midwife, '2026-08-01 08:00:00');
        $this->actingAs($midwife)->post("/maternity/orientations/{$prenatal->uuid}/complete")->assertSessionHasNoErrors();
        $pregnancy = Pregnancy::query()->sole();

        [, $delivery] = $this->passage($patient, $midwife, '2026-09-24 04:00:00');
        $this->actingAs($midwife)->post("/maternity/orientations/{$delivery->uuid}/parcours", ['encounter_type' => 'DELIVERY'])
            ->assertSessionHasNoErrors();

        $this->actingAs($midwife)->get("/maternity/orientations/{$delivery->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('encounter.type', 'DELIVERY')
                ->where('encounter.completion_label', 'Clôturer l’accouchement')
                ->has('activePregnancies', 1)
                ->has('pregnancyHistory', 1));

        $this->save($midwife, $delivery, [
            'pregnancy_choice' => 'CONTINUE',
            'pregnancy_uuid' => $pregnancy->uuid,
            'labor_data' => ['started_at' => '2026-09-24T02:10', 'membranes_status' => 'RUPTURED', 'cervical_dilation_cm' => 6],
            'delivery_data' => ['occurred_at' => '2026-09-24T06:40', 'mode' => 'VAGINAL'],
            'newborn_data' => ['newborns' => [['sex' => 'F', 'birth_weight_g' => 2600], ['sex' => 'M', 'birth_weight_g' => 2750]]],
        ])->assertSessionHasNoErrors();

        // Enregistré, pas encore clôturé : la grossesse court toujours.
        $this->assertSame(PregnancyStatus::Ongoing, $pregnancy->fresh()->status);

        $this->actingAs($midwife)->post("/maternity/orientations/{$delivery->uuid}/complete")->assertSessionHasNoErrors();

        $pregnancy->refresh();
        $this->assertSame(PregnancyStatus::Delivered, $pregnancy->status);
        $this->assertSame('2026-09-24 06:40:00', $pregnancy->delivered_at->format('Y-m-d H:i:s'), 'L’heure clinique, jamais now().');
        $this->assertSame(1, Pregnancy::query()->count(), 'Des jumeaux restent une seule grossesse.');
        $this->assertSame(2, MaternityRecord::query()->where('pregnancy_id', $pregnancy->id)->count());
        Carbon::setTestNow();
    }

    public function test_a_delivery_without_a_birth_time_keeps_the_pregnancy_ongoing(): void
    {
        $midwife = $this->midwife();
        [, $orientation] = $this->passage($this->patient(), $midwife, '2026-09-24 04:00:00');
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/parcours", ['encounter_type' => 'DELIVERY']);
        $this->save($midwife, $orientation, ['pregnancy_choice' => 'CREATE', 'labor_data' => ['cervical_dilation_cm' => 3]])
            ->assertSessionHasNoErrors();

        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();

        $this->assertSame(PregnancyStatus::Ongoing, Pregnancy::query()->sole()->status);
    }

    // ── File Maternité ───────────────────────────────────────────────────

    public function test_the_queue_filters_by_encounter_and_take_charge_starts_the_chosen_one(): void
    {
        $midwife = $this->midwife();
        $delivery = $this->arrived($midwife, 'A-26-7001', 'MAT-DELIVERY-SIMPLE');
        $prenatal = $this->arrived($midwife, 'A-26-7002', 'MAT-CONSULT-PRENATAL');
        $unknown = $this->arrived($midwife, 'A-26-7003', null);

        $props = fn (string $query) => $this->actingAs($midwife)->get("/maternity{$query}")->viewData('page')['props'];

        $all = $props('');
        $this->assertSame('all', $all['type']);
        $this->assertSame(3, $all['typeCounts']['all']);
        $this->assertSame(1, $all['typeCounts']['PRENATAL']);
        $this->assertSame(1, $all['typeCounts']['DELIVERY']);
        $this->assertSame('DELIVERY', $all['encounters'][$delivery->uuid]['suggested']);
        $this->assertNull($all['encounters'][$unknown->uuid]['suggested']);

        $this->assertSame([$prenatal->uuid], collect($props('?type=PRENATAL')['passages']['data'])->pluck('uuid')->all());
        $this->assertSame([$delivery->uuid], collect($props('?type=DELIVERY')['passages']['data'])->pluck('uuid')->all());
        $this->assertSame('all', $props('?type=INVENTED')['type'], 'Un filtre inconnu ne filtre rien.');

        // La suggestion ne décide pas : la sage-femme choisit une consultation pour le passage suggéré « accouchement ».
        $this->actingAs($midwife)->post(route('maternity.passages.take-charge', $delivery), ['encounter_type' => 'PRENATAL'])
            ->assertRedirect();
        $this->assertSame(MaternityEncounterType::Prenatal, MaternityRecord::query()->where('episode_id', $delivery->id)->sole()->encounter_type);

        // Le parcours choisi l'emporte sur la suggestion : le passage quitte « Accouchements ».
        $this->assertSame([$delivery->uuid], collect($props('?type=PRENATAL&view=in_progress')['passages']['data'])->pluck('uuid')->all());
        $this->assertSame([], collect($props('?type=DELIVERY&view=in_progress')['passages']['data'])->pluck('uuid')->all());
    }

    /**
     * Un dossier d'avant le choix du parcours se range là où il s'affiche :
     * déduit de son contenu, compté dans le même onglet que sa ligne. Dès qu'un
     * dossier existe, la suggestion de la Réception ne décide plus rien.
     */
    public function test_a_record_without_a_chosen_encounter_is_counted_where_it_is_shown(): void
    {
        $midwife = $this->midwife();
        $born = $this->arrived($midwife, 'A-26-7011', null);
        $consulted = $this->arrived($midwife, 'A-26-7012', 'MAT-DELIVERY-SIMPLE');
        $taken = fn (Episode $episode) => tap(app(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Reception, CatalogModule::Maternity, $midwife, 'Suivi'))->accept($midwife);

        // Aucun parcours choisi ; l'un porte un accouchement consigné, l'autre rien d'un travail.
        MaternityRecord::query()->create([
            'episode_id' => $born->id, 'episode_orientation_id' => $taken($born)->id, 'encounter_type' => null,
            'delivery_data' => ['occurred_at' => '2026-09-26 03:10'], 'created_by' => $midwife->id, 'updated_by' => $midwife->id,
        ]);
        MaternityRecord::query()->create([
            'episode_id' => $consulted->id, 'episode_orientation_id' => $taken($consulted)->id, 'encounter_type' => null,
            'labor_data' => ['membranes' => 'UNKNOWN'], 'created_by' => $midwife->id, 'updated_by' => $midwife->id,
        ]);

        $props = fn (string $query) => $this->actingAs($midwife)->get("/maternity{$query}")->viewData('page')['props'];
        $all = $props('?view=in_progress');

        $this->assertSame('DELIVERY', $all['encounters'][$born->uuid]['type']);
        $this->assertTrue($all['encounters'][$born->uuid]['inferred']);
        $this->assertSame('PRENATAL', $all['encounters'][$consulted->uuid]['type'], 'Un « UNKNOWN » n’est pas un travail.');
        $this->assertNull($all['encounters'][$consulted->uuid]['suggested'], 'Le dossier existe : la suggestion ne compte plus.');
        $this->assertNotNull($all['encounters'][$born->uuid]['record_updated_at']);

        $this->assertSame(1, $all['typeCounts']['DELIVERY']);
        $this->assertSame(1, $all['typeCounts']['PRENATAL']);
        $this->assertSame([$born->uuid], collect($props('?view=in_progress&type=DELIVERY')['passages']['data'])->pluck('uuid')->all());
        $this->assertSame([$consulted->uuid], collect($props('?view=in_progress&type=PRENATAL')['passages']['data'])->pluck('uuid')->all());
    }

    public function test_take_charge_without_a_choice_opens_no_record(): void
    {
        $midwife = $this->midwife();
        $episode = $this->arrived($midwife, 'A-26-7004', 'MAT-CONSULT-PRENATAL');

        $this->actingAs($midwife)->post(route('maternity.passages.take-charge', $episode))->assertRedirect();

        $this->assertSame(0, MaternityRecord::query()->count(), 'Aucun dossier ne naît d’un regard ni d’une suggestion.');
    }

    // ── Outils ───────────────────────────────────────────────────────────

    private function save(User $user, EpisodeOrientation $orientation, array $data)
    {
        return $this->actingAs($user)->put("/maternity/orientations/{$orientation->uuid}/record", $data);
    }

    /** @return array{Episode, EpisodeOrientation} */
    private function passage(Patient $patient, User $midwife, string $startedAt): array
    {
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $midwife);
        $episode->forceFill(['started_at' => $startedAt])->saveQuietly();
        $orientation = app(CreateEpisodeOrientationAction::class)->execute(
            $episode, CatalogModule::Reception, CatalogModule::Maternity, $midwife, 'Suivi obstétrical',
        );
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/accept")->assertRedirect();

        return [$episode->fresh(), $orientation->fresh(['episode'])];
    }

    /** @return array{Episode, EpisodeOrientation} */
    private function prenatal(Patient $patient, User $midwife, string $startedAt): array
    {
        [$episode, $orientation] = $this->passage($patient, $midwife, $startedAt);
        $this->actingAs($midwife)->post("/maternity/orientations/{$orientation->uuid}/parcours", ['encounter_type' => 'PRENATAL'])
            ->assertSessionHasNoErrors();

        return [$episode, $orientation];
    }

    /** Une consultation prénatale déjà rattachée à la grossesse active (ou à une nouvelle). */
    private function linkedPrenatal(Patient $patient, User $midwife, string $startedAt): array
    {
        [$episode, $orientation] = $this->prenatal($patient, $midwife, $startedAt);
        $active = Pregnancy::query()->where('patient_id', $patient->id)->where('status', PregnancyStatus::Ongoing)->first();
        $this->save($midwife, $orientation, $active
            ? ['pregnancy_choice' => 'CONTINUE', 'pregnancy_uuid' => $active->uuid]
            : ['pregnancy_choice' => 'CREATE', 'pregnancy_data' => ['last_menstrual_period' => '2026-02-01']])
            ->assertSessionHasNoErrors();

        return [$episode, $orientation];
    }

    /** Un passage accueilli, avec l'acte Maternité demandé à la Réception. */
    private function arrived(User $actor, string $number, ?string $code): Episode
    {
        $patient = Patient::query()->create([
            'patient_number' => $number, 'first_name' => 'Hanta', 'last_name' => 'Rabe', 'birth_date' => '1995-02-01', 'sex' => 'F',
        ]);
        $episode = app(CreateEpisodeAction::class)->execute($patient, actor: $actor);
        $episode->forceFill(['service_plan_finalized_at' => now()])->save();

        if ($code !== null) {
            $item = CatalogItem::query()->firstOrCreate(['code' => $code], [
                'name' => $code, 'type' => CatalogItemType::Service, 'module' => CatalogModule::Maternity,
                'unit' => 'acte', 'billable' => true, 'stockable' => false,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            EpisodeServiceRequest::query()->create([
                'episode_id' => $episode->id,
                'catalog_item_id' => $item->id,
                'catalog_item_uuid' => $item->uuid,
                'catalog_code' => $item->code,
                'designation' => $item->name,
                'module' => CatalogModule::Maternity->value,
                'routing_mode' => 'MATERNITY_DIRECT',
                'unit' => 'acte',
                'unit_price' => '10000.00',
                'currency' => 'MGA',
                'quantity' => 1,
                'created_by' => $actor->id,
            ]);
        }

        return $episode->fresh();
    }

    private function service(User $actor, string $code, string $name, CatalogModule $module): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code, 'name' => $name, 'type' => CatalogItemType::Service, 'module' => $module,
            'unit' => 'acte', 'billable' => true, 'stockable' => false,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function tariff(User $actor, CatalogItem $item, string $amount): void
    {
        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id, 'amount' => $amount, 'currency' => 'MGA',
            'effective_from' => now()->subDay(), 'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test', 'created_by' => $actor->id,
        ]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('A-26-####'),
            'first_name' => 'Marie',
            'last_name' => 'Rakoto',
            'birth_date' => '1994-06-10',
            'sex' => 'F',
        ]);
    }

    /**
     * Une sage-femme telle que son profil la recommande. Les droits d'examen
     * (`laboratory_orders.*`, `imaging_*`) ne sont pas au socle NURSE : ils
     * s'accordent au compte quand la clinique le décide.
     */
    private function midwife(bool $withExams = false): User
    {
        $profile = ProfessionalProfile::query()->where('code', 'MIDWIFE')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
            'professional_profile_id' => $profile->id,
        ]);
        $user->permissions()->syncWithoutDetaching($profile->recommendedPermissions->mapWithKeys(
            fn (Permission $permission) => [$permission->id => ['effect' => 'allow']],
        )->all());

        if ($withExams) {
            $user->permissions()->syncWithoutDetaching(Permission::query()
                ->whereIn('name', ['laboratory_orders.create', 'laboratory_orders.view', 'imaging_orders.create', 'imaging_orders.view', 'imaging_results.create'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['effect' => 'allow']])
                ->all());
        }

        return $user->fresh(['role', 'professionalProfile']);
    }
}
