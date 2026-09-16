<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CreateLabRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ClinicalExamSystem;
use App\Enums\ClinicalSystemStatus;
use App\Enums\ConsultationStep;
use App\Enums\ConsultationStepStatus;
use App\Enums\DiagnosisType;
use App\Enums\GeneralCondition;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\ClinicalExamination;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ConsultationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The complementary-exam decision, taken at the end of the clinical
 * examination instead of costing a wizard step of its own.
 *
 * Two guarantees run through these tests: "non nécessaire" is a decision the
 * doctor took — never an omission, never a normal result — and a request
 * already sent is never made to disappear.
 */
class ComplementaryExamDecisionTest extends TestCase
{
    use RefreshDatabase;

    /** Nothing is pre-selected: an unanswered question stays unanswered. */
    public function test_the_decision_starts_undecided(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [...$this->examPayload(), 'complete' => false])
            ->assertSessionHasNoErrors();

        $this->assertNull(
            $orientation->consultation()->firstOrFail()->clinicalExamination->complementary_exams_required,
        );
    }

    /**
     * « Non » déclare l'étape non nécessaire, puis **avance**.
     *
     * Elle renvoyait à l'Examen clinique, que le médecin venait justement de
     * terminer : répondre à la question le faisait repartir en arrière. La
     * suite vient désormais de `ConsultationWorkflow::nextStepAfter()`, pas
     * d'une route codée en dur.
     */
    public function test_answering_no_skips_the_paraclinical_step_and_continues_the_pathway(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => false])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/ordonnance");

        $consultation = $orientation->consultation()->firstOrFail();
        $step = $consultation->steps()->where('step', ConsultationStep::Paraclinical->value)->sole();

        $this->assertFalse($consultation->clinicalExamination->complementary_exams_required);
        // SKIPPED, not COMPLETED: the step was declared unnecessary, it was
        // not carried out.
        $this->assertSame(ConsultationStepStatus::Skipped, $step->status);
        $this->assertSame($doctor->id, $step->completed_by);
        $this->assertNotNull($step->skip_reason);
    }

    /**
     * Le raccourci « Passer cette étape » n'annulait rien.
     *
     * Il marquait la Paraclinique « non nécessaire » pendant qu'une demande
     * restait active au Laboratoire ou en Imagerie : le dossier annonçait
     * « aucun examen complémentaire » alors que le service avait toujours
     * l'examen à réaliser. Le chemin correct — la question en tête d'étape —
     * annule les demandes avec auteur, date et motif (ADR-079).
     */
    public function test_the_step_cannot_be_declared_unnecessary_while_a_request_is_live(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                'step' => ConsultationStep::Paraclinical->value,
                'intent' => 'SKIP',
            ])
            ->assertSessionHasErrors('step');

        $consultation = $orientation->consultation()->firstOrFail();
        $step = $consultation->steps()->where('step', ConsultationStep::Paraclinical->value)->first();

        $this->assertNotSame(ConsultationStepStatus::Skipped, $step?->status);
        $this->assertNull($request->fresh()->cancelled_at, 'la demande ne doit pas être touchée par un refus');
    }

    /** Le chemin prévu, lui, annule proprement puis déclare l'étape non nécessaire. */
    public function test_answering_no_cancels_the_live_request_and_then_skips(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => false, 'withdraw_confirmed' => true])
            ->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();
        $step = $consultation->steps()->where('step', ConsultationStep::Paraclinical->value)->sole();

        $this->assertSame(ConsultationStepStatus::Skipped, $step->status);
        $this->assertNotNull($request->fresh()->cancelled_at);
    }

    /**
     * Deux clics sur « Envoyer la demande » créaient deux ECG réellement
     * distincts : le service en voyait deux à réaliser, et le compteur
     * affichait « 2 » sans mentir. L'unicité est vérifiée côté serveur, sous
     * le verrou de la consultation.
     */
    public function test_the_same_exam_cannot_be_requested_twice_while_it_is_still_pending(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $first = $this->labRequest($orientation, $doctor);
        $catalogUuid = $first->items->first()->catalogItem->uuid;

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
                'items' => [['catalog_item_uuid' => $catalogUuid]],
            ])
            ->assertSessionHasErrors('lab_request');

        $this->assertSame(
            1,
            $orientation->consultation()->firstOrFail()->labRequests()->count(),
            'aucune seconde demande ne doit exister',
        );
    }

    /** Une demande retirée libère la place : la redemander est légitime. */
    public function test_the_same_exam_can_be_requested_again_once_the_first_is_withdrawn(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $first = $this->labRequest($orientation, $doctor);
        $catalogUuid = $first->items->first()->catalogItem->uuid;

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/paraclinical-requests/cancel", [
                'kind' => 'lab',
                'uuid' => $first->uuid,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($first->fresh()->cancelled_at);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
                'items' => [['catalog_item_uuid' => $catalogUuid]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $orientation->consultation()->firstOrFail()->labRequests()->count());
    }

    /** Retirer est un changement d'état, jamais une suppression (ADR-010). */
    public function test_withdrawing_keeps_the_request_and_its_trace(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/paraclinical-requests/cancel", [
                'kind' => 'lab',
                'uuid' => $request->uuid,
                'reason' => 'Finalement sans indication',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $request->fresh();

        $this->assertNotNull($fresh, 'la demande ne doit jamais être supprimée');
        $this->assertSame($doctor->id, $fresh->cancelled_by);
        $this->assertSame('Finalement sans indication', $fresh->cancel_reason);
        $this->assertSame('CANCELLED', $fresh->displayStatus());
    }

    /** Envoyer une demande ne renvoie plus à l'Examen clinique (ADR-098). */
    public function test_sending_a_request_never_sends_the_doctor_back_to_the_examination(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $item = $this->laboratoryCatalogItem($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
                'items' => [['catalog_item_uuid' => $item->uuid]],
                'continue_to_diagnosis' => true,
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/ordonnance");
    }

    /**
     * L'écran ne doit pas proposer ce que le serveur refusera.
     *
     * Le sélecteur listait l'examen déjà demandé : on pouvait le préparer,
     * le voir à côté de la demande transmise, et n'apprendre qu'au clic que
     * l'envoi était impossible.
     */
    public function test_the_page_marks_an_exam_that_is_already_pending(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);
        $catalogUuid = $request->items->first()->catalogItem->uuid;

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // La demande transmise porte l'UUID de prestation que le
                // sélecteur compare pour se désactiver.
                ->where('consultation.lab_requests.0.items.0.catalog_item_uuid', $catalogUuid)
                ->where('consultation.lab_requests.0.status', 'REQUESTED'));
    }

    /** "Non nécessaire" is not "not started" and not "normal". */
    public function test_the_stepper_says_the_paraclinical_step_was_not_required(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)->post($this->decisionUrl($orientation), ['required' => false]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.steps.paraclinique.status', ConsultationStepStatus::Skipped->value)
                ->where('consultation.steps.paraclinique.note', 'Non nécessaire')
            );
    }

    public function test_answering_yes_without_any_request_sends_the_doctor_to_select_them(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => true])
            ->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertTrue($consultation->clinicalExamination->complementary_exams_required);
        // Never skipped: the doctor said exams are needed.
        $this->assertSame(
            0,
            $consultation->steps()
                ->where('step', ConsultationStep::Paraclinical->value)
                ->where('status', ConsultationStepStatus::Skipped->value)
                ->count(),
        );
    }

    public function test_answering_yes_with_a_request_counts_it_in_the_stepper(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->labRequest($orientation, $doctor);

        $this->actingAs($doctor)->post($this->decisionUrl($orientation), ['required' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.steps.paraclinique.note', '1 examen demandé')
            );
    }

    /**
     * The heart of §19: changing one's mind is allowed, losing a request
     * silently is not.
     */
    public function test_switching_to_no_requires_confirmation_when_requests_exist(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => false])
            ->assertSessionHasErrors('required');

        $this->assertNull($request->fresh()->cancelled_at, 'Nothing may be withdrawn without confirmation.');
    }

    public function test_a_confirmed_switch_cancels_the_requests_without_deleting_them(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => false, 'withdraw_confirmed' => true])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/ordonnance");

        $request->refresh();

        // Cancelled, never deleted (ADR-010): the row and its author remain.
        $this->assertDatabaseHas('lab_requests', ['id' => $request->id]);
        $this->assertNotNull($request->cancelled_at);
        $this->assertSame($doctor->id, $request->cancelled_by);
        $this->assertNotNull($request->cancel_reason);
        $this->assertSame('CANCELLED', $request->displayStatus());
    }

    /**
     * Un examen déjà rendu peut être redemandé : c'est un nouvel examen
     * médical, pas un doublon. `ParaclinicalRequestGuard` ne bloque que les
     * demandes **actives** — ni annulées, ni résultées.
     */
    public function test_a_resulted_exam_can_be_requested_again_as_a_new_one(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $first = $this->labRequest($orientation, $doctor);
        $catalogUuid = $first->items->first()->catalogItem->uuid;

        $first->items()->update(['result_value' => '12', 'resulted_at' => now()]);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
                'items' => [['catalog_item_uuid' => $catalogUuid]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            2,
            $orientation->consultation()->firstOrFail()->labRequests()->count(),
            'le second examen est une demande neuve, la première reste intacte',
        );

        // La première n'est ni annulée ni réécrite : elle porte un acte réel.
        $this->assertNull($first->fresh()->cancelled_at);
    }

    /**
     * Après un envoi, le médecin reste sur la Paraclinique — d'où il peut en
     * demander une autre, en retirer une ou saisir un résultat. Le renvoyer
     * à l'Examen clinique le ferait repartir en arrière.
     */
    public function test_sending_a_request_never_returns_to_the_clinical_exam(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $item = \App\Models\CatalogItem::query()->create([
            'code' => 'LAB-'.uniqid(),
            'name' => 'Ionogramme',
            'type' => \App\Enums\CatalogItemType::Service,
            'module' => \App\Enums\CatalogModule::Laboratory,
            'unit' => 'analyse',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
                'items' => [['catalog_item_uuid' => $item->uuid]],
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/paraclinique");

        // Et quand il demande explicitement de poursuivre, il avance —
        // jamais vers l'examen qu'il vient de quitter.
        $other = \App\Models\CatalogItem::query()->create([
            'code' => 'LAB-'.uniqid(),
            'name' => 'CRP',
            'type' => \App\Enums\CatalogItemType::Service,
            'module' => \App\Enums\CatalogModule::Laboratory,
            'unit' => 'analyse',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
                'items' => [['catalog_item_uuid' => $other->uuid]],
                'continue_to_diagnosis' => true,
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/ordonnance");
    }

    /** A request that produced a result is part of the record, full stop. */
    public function test_a_request_that_already_has_a_result_is_never_withdrawn(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);
        $request->items()->first()->update(['result_value' => '12', 'resulted_at' => now()]);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => false, 'withdraw_confirmed' => true])
            ->assertSessionHasErrors('required');

        $this->assertNull($request->fresh()->cancelled_at);
    }

    /** §20 — the doctor may go back from "non" to "oui". */
    public function test_switching_back_to_yes_reopens_the_paraclinical_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)->post($this->decisionUrl($orientation), ['required' => false]);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            $orientation->consultation()->firstOrFail()->clinicalExamination->complementary_exams_required,
        );
    }

    /** Answering "oui" records the answer without resolving anything yet. */
    public function test_answering_yes_records_the_answer_without_resolving_the_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => true])
            ->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertTrue($consultation->clinicalExamination->complementary_exams_required);
        $this->assertSame(
            0,
            $consultation->steps()->where('step', ConsultationStep::Paraclinical->value)->count(),
            'Saying exams are needed resolves nothing on its own.',
        );
    }

    /** A cancelled request no longer justifies the paraclinical step. */
    public function test_a_cancelled_request_stops_counting_as_pending(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $request = $this->labRequest($orientation, $doctor);
        $consultation = $orientation->consultation()->firstOrFail();

        $workflow = $this->app->make(ConsultationWorkflow::class);
        $this->assertNull($workflow->blockerFor($consultation, ConsultationStep::Paraclinical));

        $request->update(['cancelled_at' => now(), 'cancelled_by' => $doctor->id]);

        $this->assertNotNull(
            $workflow->blockerFor($consultation->fresh(), ConsultationStep::Paraclinical),
            'Once withdrawn, the request no longer stands in for a real one.',
        );
    }

    public function test_a_doctor_without_the_update_permission_cannot_decide(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $reader = $this->doctor(readOnly: true);

        $this->actingAs($reader)
            ->put($this->url($orientation), [
                ...$this->examPayload(),
                'complementary_exams_required' => false,
                'complete' => true,
            ])
            ->assertForbidden();

        $this->assertNull(ClinicalExamination::query()->first());
    }

    /**
     * ADR-095 — l'examen clinique ne décide plus de la navigation.
     *
     * Il menait directement à la Prescription lorsque le médecin répondait
     * « Oui » au diagnostic, contournant ainsi la Paraclinique sans que
     * personne réponde à la question que cette étape pose elle-même
     * (ADR-079). Il mène désormais toujours à l'étape suivante ; le saut de
     * la Paraclinique reste possible, mais parce qu'elle a été *déclarée*
     * non nécessaire — ce que ce test vérifie toujours.
     */
    public function test_the_examination_always_leads_to_the_next_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->recordDiagnosis($orientation, $doctor);

        // No complementary exams: the step is declared unnecessary first.
        $this->actingAs($doctor)->post($this->decisionUrl($orientation), ['required' => false]);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [...$this->examPayload(), 'complete' => true])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/paraclinique");

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertSame(
            ConsultationStepStatus::Skipped,
            $consultation->steps()->where('step', ConsultationStep::Paraclinical->value)->sole()->status,
        );
        // The diagnosis has no step of its own any more; what matters is that
        // it exists, and closure checks exactly that.
        $this->assertSame(1, $consultation->diagnoses()->count());
    }

    /**
     * Saying "oui" without recording anything is an intention, not a
     * diagnosis. La garantie est inchangée ; seul l'endroit où on répond a
     * bougé, de l'Examen clinique à « Décision & clôture » (ADR-095).
     */
    public function test_claiming_the_diagnosis_can_be_made_without_recording_one_is_refused(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post($this->diagnosisTimingUrl($orientation), ['ready' => true])
            ->assertSessionHasErrors('ready');

        $this->assertNull(
            $orientation->consultation()->firstOrFail()->clinicalExamination?->diagnosis_ready,
            'Une réponse refusée ne doit rien enregistrer.',
        );
    }

    /**
     * A doctor waiting for results must never be pushed to conclude. The
     * answer is kept, and the screens stay reachable to record the diagnosis
     * once the results arrive.
     */
    public function test_deferring_the_diagnosis_is_recorded_and_blocks_nothing(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->labRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->post($this->diagnosisTimingUrl($orientation), ['ready' => false])
            ->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertFalse($consultation->clinicalExamination->diagnosis_ready);

        // Un report n'est pas un examen : la ligne créée pour le porter ne
        // doit pas faire croire qu'un examen clinique a eu lieu.
        $this->assertNull($consultation->clinicalExamination->general_condition);

        // Les deux écrans restent atteignables pour conclure plus tard.
        foreach (['examen', 'cloture'] as $step) {
            $this->actingAs($doctor)
                ->get("/medicine/orientations/{$orientation->uuid}/{$step}")
                ->assertOk();
        }
    }

    /** Le report explique le blocage, au lieu de le faire passer pour un oubli. */
    public function test_a_deferred_diagnosis_is_named_as_such_in_the_closure_blockers(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $blockers = fn (): string => collect($this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/cloture")
            ->viewData('page')['props']['consultation']['closure_blockers'])->pluck('message')->implode(' ');

        $this->assertStringContainsString('aucun diagnostic enregistré', $blockers());

        $this->actingAs($doctor)
            ->post($this->diagnosisTimingUrl($orientation), ['ready' => false])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('différé par le médecin', $blockers());
    }

    /** Nothing is pre-selected here either. */
    public function test_the_diagnosis_readiness_starts_undecided(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)->put($this->url($orientation), [...$this->examPayload(), 'complete' => false]);

        $this->assertNull(
            $orientation->consultation()->firstOrFail()->clinicalExamination->diagnosis_ready,
        );
    }

    private function recordDiagnosis(EpisodeOrientation $orientation, User $doctor): void
    {
        $orientation->consultation()->firstOrFail()->diagnoses()->create([
            'type' => DiagnosisType::Final,
            'description' => 'Céphalée de tension',
            'is_manual' => true,
            'recorded_by' => $doctor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function examPayload(): array
    {
        return [
            'general_condition' => GeneralCondition::Good->value,
            'systems' => [
                ['system_code' => ClinicalExamSystem::Cardiovascular->value, 'status' => ClinicalSystemStatus::Normal->value],
            ],
        ];
    }

    private function url(EpisodeOrientation $orientation): string
    {
        return "/medicine/orientations/{$orientation->uuid}/examen-clinique";
    }

    /** The decision now has its own endpoint, at the head of the step it governs. */
    private function decisionUrl(EpisodeOrientation $orientation): string
    {
        return "/medicine/orientations/{$orientation->uuid}/complementary-exams";
    }

    /** ADR-095 — « Le diagnostic peut-il être posé maintenant ? », à la clôture. */
    private function diagnosisTimingUrl(EpisodeOrientation $orientation): string
    {
        return "/medicine/orientations/{$orientation->uuid}/diagnostic-timing";
    }

    /**
     * Built through the real action, not by hand: a lab request also opens a
     * Laboratory orientation, and a fixture that skipped it would not be the
     * thing the workflow actually reasons about.
     */
    private function laboratoryCatalogItem(User $doctor): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => 'LAB-'.uniqid(),
            'name' => 'NFS',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
    }

    /**
     * Régression : répondre « Non » sur un passage n'ayant que de
     * l'imagerie résultée produisait une erreur 500.
     *
     * Le code construisait deux collections de libellés puis les fusionnait.
     * `Eloquent\Collection::map()` ne redescend en collection de base que si
     * son résultat n'est pas vide : sans aucune analyse résultée, la première
     * restait une Eloquent\Collection, dont le `merge()` appelle `getKey()`
     * sur chaque élément — ici des chaînes.
     *
     * Le défaut ne se voyait qu'avec de l'imagerie **et** aucune analyse :
     * les tests existants avaient toujours une analyse, donc une collection
     * non vide, donc le bon type.
     */
    public function test_refusing_with_only_a_resulted_imaging_request_is_explained_not_crashed(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $imaging = $this->imagingRequestWithResult($orientation, $doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => false, 'withdraw_confirmed' => true])
            ->assertSessionHasErrors('required');

        // La demande résultée n'est pas retirée : un résultat est un acte.
        $this->assertNull($imaging->fresh()->cancelled_at);
    }

    /** Sans résultat, la même réponse retire bien la demande d'imagerie. */
    public function test_refusing_withdraws_an_imaging_request_that_has_no_result(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $imaging = $this->imagingRequest($orientation, $doctor);

        $this->actingAs($doctor)
            ->post($this->decisionUrl($orientation), ['required' => false, 'withdraw_confirmed' => true])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($imaging->fresh()->cancelled_at);
    }

    private function imagingRequestWithResult(EpisodeOrientation $orientation, User $doctor): \App\Models\ImagingRequest
    {
        $request = $this->imagingRequest($orientation, $doctor);

        $request->items()->update([
            'result_value' => '<p>Rythme sinusal régulier.</p>',
            'resulted_at' => now(),
            'resulted_by' => $doctor->id,
        ]);

        return $request->fresh('items');
    }

    private function imagingRequest(EpisodeOrientation $orientation, User $doctor): \App\Models\ImagingRequest
    {
        $item = CatalogItem::query()->create([
            'code' => 'IMG-'.uniqid(),
            'name' => 'Électrocardiogramme (ECG)',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        return $this->app->make(\App\Actions\Medicine\CreateImagingRequestAction::class)->execute(
            $orientation->consultation()->firstOrFail(),
            [['catalog_item_uuid' => $item->uuid]],
            null,
            $doctor,
        )->fresh('items');
    }

    private function labRequest(EpisodeOrientation $orientation, User $doctor): LabRequest
    {
        $item = CatalogItem::query()->create([
            'code' => 'LAB-'.uniqid(),
            'name' => 'NFS',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        return $this->app->make(CreateLabRequestAction::class)->execute(
            $orientation->consultation()->firstOrFail(),
            [['catalog_item_uuid' => $item->uuid]],
            null,
            $doctor,
        )->fresh('items');
    }

    private function doctor(bool $readOnly = false): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $readOnly ? 'MEDICINE_READER' : 'MEDICINE'],
            ['name' => 'Médecine'],
        );

        $permissions = ['medical_record.view', 'consultations.view', 'patients.view', 'care.view', 'vitals.view',
            'laboratory_orders.view', 'imaging_orders.view'];

        if (! $readOnly) {
            $permissions = [...$permissions, 'consultations.create', 'consultations.update',
                'laboratory_orders.create', 'imaging_orders.create', 'diagnoses.view', 'prescriptions.view'];
        }

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function medicineConsultation(User $doctor): array
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
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
