<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\RecordMedicalDischargeAction;
use App\Actions\Medicine\ResolveConsultationStepAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationStep;
use App\Enums\ConsultationStepStatus;
use App\Enums\DiagnosisType;
use App\Enums\MedicalDischargeType;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ConsultationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The step state machine: what a Médecine consultation owes, who said so,
 * and what closing it means.
 *
 * The guarantee under test throughout is the one the wizard could not give
 * while its progress lived in the browser — a step is never finished because
 * someone opened it, and an absence of entry is never a resolution.
 */
class ConsultationStepStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_consultation_owes_every_step_and_claims_none(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.status', ConsultationStatus::InProgress->value)
                ->where('consultation.steps.dossier.status', ConsultationStepStatus::NotStarted->value)
                ->where('consultation.steps.consultation.status', ConsultationStepStatus::NotStarted->value)
                ->where('consultation.steps.cloture.status', ConsultationStepStatus::NotStarted->value)
                // Skippable exactly where a step is clinically optional.
                ->where('consultation.steps.paraclinique.skippable', true)
                ->where('consultation.steps.ordonnance.skippable', true)
                ->where('consultation.steps.cloture.skippable', false)
            );
    }

    /**
     * Simply visiting a step's URL is the exact case the old derived progress
     * got wrong. Reading a screen is not doing the work it asks for.
     */
    public function test_opening_a_step_never_marks_it_completed(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        foreach (ConsultationStep::wizardCases() as $case) {
            $step = $case->value;
            $this->actingAs($doctor)
                ->get("/medicine/orientations/{$orientation->uuid}/{$step}")
                ->assertOk();
        }

        $this->assertSame(0, $orientation->consultation()->firstOrFail()->steps()->count());
    }

    /** « Enregistrer » keeps the work; it does not declare the step done. */
    public function test_saving_without_completing_leaves_the_step_in_progress(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
                'chief_complaint' => 'Douleur abdominale',
                'reason' => '<p>Céphalées depuis trois jours</p>',
                'current_treatments' => [],
                'complete' => false,
            ])
            // Stays on the step: the redirection follows the intent.
            ->assertRedirect();

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertSame('<p>Céphalées depuis trois jours</p>', $consultation->reason);
        $this->assertSame(
            ConsultationStepStatus::InProgress,
            $consultation->steps()->where('step', ConsultationStep::Interview->value)->sole()->status,
        );
    }

    public function test_saving_and_continuing_completes_the_step_and_records_its_author(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
                'chief_complaint' => 'Douleur abdominale',
                'reason' => '<p>Céphalées depuis trois jours</p>',
                'current_treatments' => [],
                'complete' => true,
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/examen");

        $step = $orientation->consultation()->firstOrFail()->steps()
            ->where('step', ConsultationStep::Interview->value)->sole();

        $this->assertSame(ConsultationStepStatus::Completed, $step->status);
        $this->assertSame($doctor->id, $step->completed_by);
        $this->assertNotNull($step->completed_at);
    }

    /**
     * Re-saving a validated step must not quietly demote it: the doctor
     * re-validates explicitly when a correction matters.
     */
    public function test_re_saving_a_validated_step_never_demotes_it(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $url = "/medicine/orientations/{$orientation->uuid}/interrogatoire";

        $this->actingAs($doctor)->put($url, [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => '<p>Céphalées</p>', 'current_treatments' => [], 'complete' => true,
        ]);
        $this->actingAs($doctor)->put($url, [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => '<p>Céphalées et vertiges</p>', 'current_treatments' => [], 'complete' => false,
        ]);

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertSame('<p>Céphalées et vertiges</p>', $consultation->reason);
        $this->assertSame(
            ConsultationStepStatus::Completed,
            $consultation->steps()->where('step', ConsultationStep::Interview->value)->sole()->status,
        );
    }

    /** An empty step cannot be validated: nothing was done to validate. */
    public function test_a_step_cannot_be_validated_while_its_own_minimum_is_unmet(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                'step' => ConsultationStep::Diagnosis->value,
                'intent' => 'COMPLETE',
            ])
            ->assertSessionHasErrors('step');

        $this->assertSame(0, $orientation->consultation()->firstOrFail()->steps()->count());
    }

    public function test_an_optional_step_can_be_declared_unnecessary_with_its_reason(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                'step' => ConsultationStep::Paraclinical->value,
                'intent' => 'SKIP',
                'skip_reason' => 'Aucun examen complémentaire indiqué',
            ])
            // Resolving a step moves the doctor on to the next one — which is
            // now Prescription: Diagnostic left the wizard (ADR-081).
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/ordonnance");

        $step = $orientation->consultation()->firstOrFail()->steps()
            ->where('step', ConsultationStep::Paraclinical->value)->sole();

        $this->assertSame(ConsultationStepStatus::Skipped, $step->status);
        $this->assertSame('Aucun examen complémentaire indiqué', $step->skip_reason);
        $this->assertSame($doctor->id, $step->completed_by);
    }

    /**
     * Diagnosis and the medical decision are not optional: allowing them to
     * be waved away would let an encounter close with no clinical conclusion.
     */
    public function test_a_mandatory_step_cannot_be_declared_unnecessary(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        foreach ([ConsultationStep::Diagnosis, ConsultationStep::Closure, ConsultationStep::ClinicalExam] as $step) {
            $this->actingAs($doctor)
                ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                    'step' => $step->value,
                    'intent' => 'SKIP',
                ])
                ->assertSessionHasErrors('step');
        }

        $this->assertSame(0, $orientation->consultation()->firstOrFail()->steps()->count());
    }

    public function test_closure_is_refused_while_a_relevant_step_is_unresolved(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasErrors('consultation');

        $this->assertSame(
            ConsultationStatus::InProgress,
            $orientation->consultation()->firstOrFail()->status,
        );
    }

    public function test_closure_succeeds_once_every_relevant_step_is_resolved_and_is_idempotent(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);
        $consultation = $this->resolveEveryStep($orientation, $doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasNoErrors();

        $consultation->refresh();
        $this->assertSame(ConsultationStatus::Completed, $consultation->status);
        $this->assertSame($doctor->id, $consultation->completed_by);
        $closedAt = $consultation->completed_at;

        // A double click must not produce a second closure or move the date.
        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasNoErrors();

        $this->assertTrue($closedAt->equalTo($consultation->fresh()->completed_at));
        $this->assertNotNull($episode->fresh()->medicalDischarge);
    }

    /**
     * ADR-010: validated medical data is never silently overwritten. After
     * closure the ordinary save paths refuse, and the presenter stops
     * offering the write capabilities.
     */
    public function test_a_closed_consultation_is_read_only(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $this->resolveEveryStep($orientation, $doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/complete");

        // Closing now also completes the Médecine orientation (ADR-084), so
        // the refusal comes one layer earlier than before — at authorisation
        // rather than at validation. What matters is unchanged: the write
        // does not happen.
        $this->actingAs($doctor)
            ->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
                'chief_complaint' => 'Douleur abdominale',
                'reason' => '<p>Réécriture après clôture</p>',
                'current_treatments' => [],
            ])
            ->assertForbidden();

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                'step' => ConsultationStep::Paraclinical->value,
                'intent' => 'SKIP',
            ])
            ->assertForbidden();

        $this->assertStringNotContainsString(
            'Réécriture après clôture',
            (string) $orientation->consultation()->firstOrFail()->reason,
        );
    }

    /**
     * A patient who came only for an ultrasound has no interrogation and no
     * physical examination to record. Those steps are not omissions to make
     * up before closing — but they stay reachable, never locked.
     */
    /**
     * ADR-084 : la Clôture montre « ce qui manque encore **et le chemin pour
     * y retourner** ». L'écran énonçait l'obstacle sans ce chemin, laissant
     * le médecin chercher l'étape à valider.
     */
    public function test_each_closure_blocker_carries_the_step_to_go_to(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $consultation = $orientation->consultation()->firstOrFail();

        $blockers = collect($this->app->make(ConsultationWorkflow::class)
            ->blockersForClosure($consultation));

        $this->assertNotNull(
            $blockers->first(fn (array $b): bool => str_contains($b['message'], 'Prescription'))['step'] ?? null,
            'Un obstacle d’étape doit nommer l’étape à rejoindre.',
        );

        // Le diagnostic et la conduite à tenir se règlent sur place : aucun
        // renvoi, sinon l'écran proposerait d'aller là où l'on est déjà.
        foreach ($blockers as $blocker) {
            if (str_contains($blocker['message'], 'Diagnostic :')
                || str_contains($blocker['message'], 'Conduite à tenir :')) {
                $this->assertNull($blocker['step']);
            }
        }
    }

    /**
     * ADR-129 — le dossier est une étape de lecture : ne l'avoir pas « validée »
     * ne retient plus la clôture, alors qu'il n'y a rien à y saisir.
     */
    public function test_an_unvalidated_dossier_step_never_blocks_closure(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $consultation = $orientation->consultation()->firstOrFail();
        $workflow = $this->app->make(ConsultationWorkflow::class);

        $messages = collect($workflow->blockersForClosure($consultation))->pluck('message')->implode(' ');

        // Non validée, et pourtant absente des obstacles ; les autres étapes
        // restent exigées.
        $this->assertSame(
            ConsultationStepStatus::NotStarted,
            $consultation->steps()->where('step', ConsultationStep::Dossier->value)->first()?->status ?? ConsultationStepStatus::NotStarted,
        );
        $this->assertStringNotContainsString('Dossier du passage', $messages);
        $this->assertStringContainsString('Interrogatoire', $messages);
    }

    public function test_a_paraclinical_only_encounter_owes_no_interview_or_clinical_exam(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor, CatalogModule::Imaging);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.steps.consultation.relevant', false)
                ->where('consultation.steps.examen.relevant', false)
            );

        $consultation = $orientation->consultation()->firstOrFail();
        $blockers = collect($this->app->make(ConsultationWorkflow::class)
            ->blockersForClosure($consultation))->pluck('message')->implode(' ');

        $this->assertStringNotContainsString('Interrogatoire', $blockers);
        $this->assertStringNotContainsString('Examen clinique', $blockers);

        // Reachable all the same: an encounter that turns into a real
        // consultation can still be documented.
        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/consultation")
            ->assertOk();
    }

    public function test_a_doctor_without_the_update_permission_cannot_resolve_a_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $reader = $this->doctor(['medical_record.view', 'consultations.view']);

        $this->actingAs($reader)
            ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                'step' => ConsultationStep::Paraclinical->value,
                'intent' => 'SKIP',
            ])
            ->assertForbidden();

        $this->assertSame(0, $orientation->consultation()->firstOrFail()->steps()->count());
    }

    /** Brings the encounter to a genuinely closeable state, step by step. */
    private function resolveEveryStep(EpisodeOrientation $orientation, User $doctor): Consultation
    {
        $consultation = $orientation->consultation()->firstOrFail();

        $consultation->diagnoses()->create([
            'type' => DiagnosisType::Final,
            'description' => 'Céphalée de tension',
            'is_manual' => true,
            'recorded_by' => $doctor->id,
        ]);
        // Through the real action, never by hand: recording a discharge is
        // also what registers the conduite à tenir, and closure now checks
        // that a request actually went out (ADR-084).
        $this->app->make(RecordMedicalDischargeAction::class)->execute($orientation, [
            'type' => MedicalDischargeType::Normal->value,
            'final_diagnosis' => 'Céphalée de tension',
            'patient_condition' => 'Stable, apyrétique',
            'discharged_at' => now()->toDateTimeString(),
        ], $doctor);
        $consultation->update([
            'chief_complaint' => 'Douleur abdominale',
            'reason' => '<p>Céphalées</p>',
            'clinical_exam' => '<p>Examen sans particularité</p>',
        ]);

        $resolve = $this->app->make(ResolveConsultationStepAction::class);

        foreach ([ConsultationStep::Paraclinical, ConsultationStep::Prescription] as $step) {
            $resolve->skip($consultation->refresh(), $step, null, $doctor);
        }

        // Clôture is deliberately absent: closing the consultation is what
        // resolves it, and validating it beforehand would be the same act
        // performed twice.
        foreach ([
            ConsultationStep::Dossier,
            ConsultationStep::Interview,
            ConsultationStep::ClinicalExam,
            ConsultationStep::Diagnosis,
        ] as $step) {
            $resolve->complete($consultation->refresh(), $step, $doctor);
        }

        return $consultation->refresh();
    }

    /** @param array<int, string>|null $permissions */
    private function doctor(?array $permissions = null): User
    {
        $role = Role::query()->firstOrCreate(
            ['code' => $permissions === null ? 'MEDICINE' : 'MEDICINE_READER'],
            ['name' => $permissions === null ? 'Médecine' : 'Médecine (lecture)'],
        );

        foreach ($permissions ?? [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view',
            'diagnoses.view', 'diagnoses.create', 'prescriptions.view',
            'laboratory_orders.view', 'imaging_orders.view', 'care_orders.view',
            'medical_discharge.create',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function medicineConsultation(User $doctor, CatalogModule $module = CatalogModule::Medicine): array
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
            'code' => 'SRV-'.uniqid(),
            'name' => $module === CatalogModule::Imaging ? 'Échographie abdominale' : 'Consultation générale',
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => 'examen',
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
        // ADR-177 — une prestation d'arrivée n'ouvre plus de file : l'orientation
        // vers ce service est désormais un geste réel, posé ici explicitement.
        $this->app->make(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Medicine, $doctor);
        $orientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        return [$episode->fresh(), $orientation->fresh()];
    }
}
