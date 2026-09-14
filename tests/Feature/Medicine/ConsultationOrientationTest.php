<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationOrientationStatus;
use App\Enums\ConsultationOrientationType;
use App\Enums\ConsultationStepStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\MedicalDischargeType;
use App\Enums\MedicalRequestStatus;
use App\Enums\ReceptionRoutingMode;
use App\Enums\SurgicalRequestStatus;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The conduite à tenir as a business datum (ADR-084).
 *
 * The guarantee under test throughout: the doctor states where the patient
 * is going at the moment they know it, the matching request opens there and
 * then, and no destination is ever chosen twice.
 */
class ConsultationOrientationTest extends TestCase
{
    use RefreshDatabase;

    /** §31.1 — orientation Chirurgie décidée dès l'examen clinique. */
    public function test_the_doctor_orients_to_surgery_from_the_clinical_examination(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/orientation", [
                'type' => ConsultationOrientationType::Surgery->value,
                'priority' => 'URGENT',
            ])
            ->assertSessionHasNoErrors();

        $active = $orientation->consultation()->firstOrFail()->activeOrientation;
        $this->assertSame(ConsultationOrientationType::Surgery, $active->type);
        $this->assertSame(ConsultationOrientationStatus::Selected, $active->status);
        $this->assertSame('URGENT', $active->priority->value);
        // Choosing is not transmitting: nothing reached the block yet.
        $this->assertDatabaseCount('surgical_requests', 0);
    }

    /**
     * §31.2 — the surgical request is created straight from the examination,
     * without the doctor ever crossing a "Décision" screen to re-select a
     * destination they already chose. That round trip is precisely what this
     * refactor removes.
     */
    public function test_a_surgical_request_is_transmitted_without_any_decision_step(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);
        $intervention = $this->surgeryItem($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/surgical-referrals", [
                'catalog_item_uuid' => $intervention->uuid,
                'diagnostic' => 'Appendicite aiguë',
                'indication' => 'Douleur FID, défense',
                'priority' => 'URGENT',
                'return_step' => 'examen',
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/examen");

        $active = $orientation->consultation()->firstOrFail()->activeOrientation;
        $this->assertSame(ConsultationOrientationStatus::Submitted, $active->status);
        $this->assertNotNull($active->surgical_request_id);
        $this->assertSame(
            SurgicalRequest::query()->sole()->getKey(),
            $active->surgical_request_id,
        );
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => 'SURGERY',
        ]);
        // One passage, never a second one for a referral.
        $this->assertSame(1, Episode::query()->count());
    }

    /** §31.3 — hospitalisation : une demande réelle, jamais une admission. */
    public function test_the_hospitalization_request_records_what_the_doctor_asks_for(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
                'reason' => 'Déshydratation sévère',
                'admission_diagnosis' => 'Gastro-entérite aiguë',
                'requested_service' => 'Médecine interne',
                'priority' => 'URGENT',
                'instructions' => 'Réhydratation IV à l’arrivée.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('hospitalization_requests', [
            'episode_id' => $episode->id,
            'reason' => 'Déshydratation sévère',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
            'status' => MedicalRequestStatus::Requested->value,
        ]);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => 'HOSPITALIZATION',
        ]);
    }

    /** §31.4 — référence / transfert, avec son établissement destinataire. */
    public function test_the_referral_records_its_destination_and_can_be_printed(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
                'facility' => 'CHU Mahajanga',
                'reason' => 'Plateau technique insuffisant',
                'diagnosis' => 'Traumatisme crânien',
                'priority' => 'URGENT',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('medical_referrals', [
            'episode_id' => $episode->id,
            'facility' => 'CHU Mahajanga',
            'status' => MedicalRequestStatus::Requested->value,
        ]);

        $referral = $orientation->consultation()->firstOrFail()->activeOrientation->medicalReferral;

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/medical-referrals/{$referral->uuid}/print")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/MedicalReferralPrint')
                ->where('referral.facility', 'CHU Mahajanga'));
    }

    /** §31.5 — la sortie médicale n'interrompt plus la consultation. */
    public function test_a_medical_discharge_records_the_orientation_without_closing_the_encounter(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/discharge", [
                'type' => MedicalDischargeType::Normal->value,
                'final_diagnosis' => 'Rhinopharyngite',
                'patient_condition' => 'Stable',
                'recommendations' => 'Repos, hydratation.',
                'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasNoErrors();

        $active = $orientation->consultation()->firstOrFail()->activeOrientation;
        $this->assertSame(ConsultationOrientationType::Discharge, $active->type);
        $this->assertNotNull($active->medical_discharge_id);
        // Still open: the doctor may prescribe or print before closing.
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
    }

    /**
     * §31.6 and §31.7 — an orientation settled early does not force the
     * doctor through screens that have nothing to do with this patient.
     * NOT_REQUIRED is recorded as such, and never as "done".
     */
    public function test_paraclinical_and_prescription_are_recorded_as_not_required_not_as_done(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        foreach (['paraclinique', 'ordonnance'] as $step) {
            $this->actingAs($doctor)
                ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                    'step' => $step,
                    'intent' => 'SKIP',
                    'skip_reason' => 'Urgence chirurgicale.',
                ])
                ->assertSessionHasNoErrors();
        }

        $steps = $orientation->consultation()->firstOrFail()->steps()
            ->pluck('status', 'step')
            ->map(fn (ConsultationStepStatus $status): string => $status->value);
        // NOT_REQUIRED is not COMPLETED: a step declared unnecessary is not a
        // step that was medically carried out, and the record says which.
        $this->assertSame('SKIPPED', $steps['paraclinique']);
        $this->assertSame('SKIPPED', $steps['ordonnance']);
        $this->assertNotSame('COMPLETED', $steps['paraclinique']);
    }

    /**
     * §31.8, §31.9 and §31.10 — changing course before closure cancels the
     * request that went out and keeps both intentions readable.
     */
    public function test_changing_orientation_cancels_the_previous_request_and_keeps_the_history(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Surveillance',
            'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'facility' => 'CHU Mahajanga',
            'reason' => 'Plateau technique',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();
        $this->assertSame(ConsultationOrientationType::Referral, $consultation->activeOrientation->type);
        // The first request is cancelled, not deleted…
        $this->assertDatabaseHas('hospitalization_requests', [
            'status' => MedicalRequestStatus::Cancelled->value,
            'cancelled_by' => $doctor->id,
        ]);
        $this->assertDatabaseHas('episode_orientations', [
            'destination_module' => 'HOSPITALIZATION',
            'status' => EpisodeOrientationStatus::Cancelled->value,
        ]);
        // …and the first intention stays in the consultation's history.
        $this->assertSame(2, $consultation->orientations()->count());
        $this->assertSame(
            1,
            $consultation->orientations()->whereNotNull('cancelled_at')->count(),
        );
    }

    /**
     * The limit of that freedom: once the destination has taken the request
     * up, Médecine no longer disposes of it. Refused with a message rather
     * than unpicked behind the other service's back.
     */
    public function test_an_orientation_cannot_be_changed_once_surgery_took_the_request_up(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/surgical-referrals", [
            'catalog_item_uuid' => $this->surgeryItem($doctor)->uuid,
            'diagnostic' => 'Appendicite aiguë',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        SurgicalRequest::query()->sole()->update(['status' => SurgicalRequestStatus::Scheduled]);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/orientation", [
                'type' => ConsultationOrientationType::Discharge->value,
            ])
            ->assertSessionHasErrors('orientation');

        $this->assertSame(
            ConsultationOrientationType::Surgery,
            $orientation->consultation()->firstOrFail()->activeOrientation->type,
        );
    }

    /**
     * §31.11 and §31.12 — the last step verifies and closes. It never asks
     * again what the orientation is, and it refuses to close while the
     * chosen destination has been told nothing.
     */
    public function test_closure_refuses_a_selected_but_untransmitted_orientation(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->prepareEveryStepButOrientation($orientation, $doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/orientation", [
                'type' => ConsultationOrientationType::Hospitalization->value,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasErrors('consultation');

        // Transmitting the request is what unblocks the closure — the doctor
        // is never asked to restate the destination.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Surveillance rapprochée',
            'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/complete")
            ->assertSessionHasNoErrors();

        $this->assertSame('COMPLETED', $orientation->consultation()->firstOrFail()->status->value);
        $this->assertSame(EpisodeOrientationStatus::Completed, $orientation->fresh()->status);
    }

    /** §31.13 — une orientation urgente part immédiatement. */
    public function test_an_urgent_orientation_is_transmitted_immediately(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor, EpisodePriority::Emergency);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/surgical-referrals", [
                'catalog_item_uuid' => $this->surgeryItem($doctor)->uuid,
                'diagnostic' => 'Abdomen aigu',
                'priority' => 'URGENT',
            ])
            ->assertSessionHasNoErrors();

        $active = $orientation->consultation()->firstOrFail()->activeOrientation;
        $this->assertSame('URGENT', $active->priority->value);
        $this->assertSame(ConsultationOrientationStatus::Submitted, $active->status);
        // Nothing administrative held the transmission back.
        $this->assertNotNull($active->submitted_at);
    }

    /** §31.14 — chaque destination exige sa permission effective. */
    public function test_a_destination_requires_its_own_permission(): void
    {
        $limited = $this->doctor(['medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update', 'patients.view'], 'MEDICINE_LIMITED');
        [, $orientation] = $this->consultation($limited);

        $this->actingAs($limited)
            ->post("/medicine/orientations/{$orientation->uuid}/orientation", [
                'type' => ConsultationOrientationType::Surgery->value,
            ])
            ->assertSessionHasErrors('type');

        $this->actingAs($limited)
            ->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
                'reason' => 'Surveillance',
                'priority' => 'NORMAL',
            ])
            ->assertForbidden();

        $this->assertNull($orientation->consultation()->firstOrFail()->activeOrientation);
    }

    /** §31.15 — le stepper suit l'état réel, y compris l'orientation. */
    public function test_the_stepper_reports_the_orientation_and_no_longer_a_decision_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/examen")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('consultation.steps.cloture')
                ->missing('consultation.steps.decision')
                ->where('consultation_orientation.active', null));

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'facility' => 'CHU Mahajanga',
            'reason' => 'Plateau technique',
            'priority' => 'URGENT',
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/examen")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation_orientation.active.type', 'REFERRAL')
                ->where('consultation_orientation.active.status', 'SUBMITTED')
                ->where('consultation_orientation.active.request.summary', 'CHU Mahajanga'));
    }

    /**
     * Le trou que cette étape comble : un patient venu uniquement pour une
     * échographie n'a ni interrogatoire ni examen clinique (ADR-076). Sa
     * conduite à tenir doit donc pouvoir être décidée sans passer par un
     * écran sans objet — sinon le médecin ne peut jamais clôturer.
     */
    public function test_a_paraclinical_only_encounter_can_be_concluded_without_a_clinical_examination(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor, module: CatalogModule::Imaging);

        // L'écran le dit lui-même : ces deux étapes sont sans objet ici.
        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.steps.examen.relevant', false)
                ->where('consultation.steps.consultation.relevant', false)
                ->has('consultation_orientation'));

        // Le diagnostic et l'orientation se consignent depuis la Paraclinique.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Hydrosalpinx',
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'facility' => 'CHU Mahajanga',
            'reason' => 'Prise en charge spécialisée',
            'priority' => 'NORMAL',
            'return_step' => 'paraclinique',
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/paraclinique");

        foreach ([
            ['step' => 'dossier', 'intent' => 'COMPLETE'],
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

        $this->assertSame('COMPLETED', $orientation->consultation()->firstOrFail()->status->value);
    }

    /**
     * ADR-089 — every patient concludes on the same step: the blockers never
     * send the doctor back to the examination or the paraclinical screen.
     */
    public function test_the_closure_blockers_point_every_patient_to_the_decision_step(): void
    {
        $doctor = $this->doctor();
        [, $imagingOnly] = $this->consultation($doctor, module: CatalogModule::Imaging);
        [, $normal] = $this->consultation($doctor);

        $blockersFor = fn (string $uuid): string => implode(' ', $this->actingAs($doctor)
            ->get("/medicine/orientations/{$uuid}/cloture")
            ->viewData('page')['props']['consultation']['closure_blockers']);

        foreach ([$blockersFor($imagingOnly->uuid), $blockersFor($normal->uuid)] as $blockers) {
            $this->assertStringContainsString('Diagnostic : aucun diagnostic enregistré — posez-le à l’étape Décision & clôture.', $blockers);
            $this->assertStringContainsString('Conduite à tenir : indiquez la suite de la prise en charge (étape Décision & clôture).', $blockers);
            $this->assertStringNotContainsString('Paraclinique', $blockers);
            $this->assertStringNotContainsString('examen clinique', $blockers);
        }
    }

    /** ADR-089 — a diagnosis recorded at « Décision & clôture » keeps the doctor there. */
    public function test_a_diagnosis_recorded_from_the_decision_step_stays_on_that_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Otite moyenne aiguë',
            'return_step' => 'cloture',
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/cloture");

        // Without a step, the examination stays the default.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Rhinite',
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/examen");
    }

    /** An old bookmark must land on the screen that carries the function. */
    public function test_the_old_decision_url_redirects_to_the_closure_step(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/decision")
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/cloture");
    }

    /**
     * §17 — the referral forms arrive filled with what the doctor already
     * wrote. Nothing is ever asked twice.
     */
    public function test_the_request_forms_are_prefilled_from_what_was_already_recorded(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => '<p>Douleur depuis 3 jours</p>',
            'current_treatments' => [],
        ]);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Appendicite aiguë',
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/examen")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation_orientation.prefill.reason', 'Douleur abdominale')
                ->where('consultation_orientation.prefill.diagnosis', 'Appendicite aiguë'));
    }

    /** @param array<int, string>|null $permissions */
    private function doctor(?array $permissions = null, string $roleCode = 'MEDICINE'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => 'Médecine']);

        foreach ($permissions ?? [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view',
            'diagnoses.view', 'diagnoses.create', 'prescriptions.view',
            'laboratory_orders.view', 'imaging_orders.view',
            'surgery.request', 'hospitalization.request', 'maternity.request',
            'transfer.request', 'pediatrics.request', 'medical_discharge.create',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function consultation(
        User $doctor,
        EpisodePriority $priority = EpisodePriority::Normal,
        CatalogModule $module = CatalogModule::Medicine,
    ): array {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient, $priority, $doctor);
        $item = CatalogItem::query()->create([
            'code' => 'CONSULT-'.uniqid(),
            'name' => $module === CatalogModule::Medicine ? 'Consultation générale' : 'Échographie',
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => $module === CatalogModule::Medicine ? 'consultation' : 'examen',
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
            ->where('status', EpisodeOrientationStatus::Pending->value)
            ->latest('id')
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($medicine, $doctor);

        return [$episode->fresh(), $medicine->fresh()];
    }

    private function surgeryItem(User $doctor): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => 'APP-'.uniqid(),
            'name' => 'Appendicectomie',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Surgery,
            'unit' => 'intervention',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
    }

    /** Everything a closure needs except the conduite à tenir itself. */
    private function prepareEveryStepButOrientation(EpisodeOrientation $orientation, User $doctor): void
    {
        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => '<p>Douleur depuis 3 jours</p>',
            'current_treatments' => [],
        ]);
        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => '<p>Sensibilité en fosse iliaque droite</p>',
        ]);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Appendicite aiguë',
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
    }
}
