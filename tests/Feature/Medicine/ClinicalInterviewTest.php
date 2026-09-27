<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationEvolution;
use App\Enums\ConsultationStep;
use App\Enums\ConsultationStepStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The semi-structured interview.
 *
 * Two guarantees run through every test: what the patient says today is
 * kept on the consultation whatever happens to the permanent record, and
 * the permanent record is never written without an explicit, permitted act.
 */
class ClinicalInterviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_chief_complaint_separately_from_the_narrative(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Douleur abdominale persistante',
                'reason' => '<p>Depuis trois jours, majorée après les repas.</p>',
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();

        // Two distinct fields: the short one feeds lists and search, the
        // rich one stays the narrative. Neither absorbs the other.
        $this->assertSame('Douleur abdominale persistante', $consultation->chief_complaint);
        $this->assertSame('<p>Depuis trois jours, majorée après les repas.</p>', $consultation->reason);
        $this->assertSame($doctor->id, $consultation->interviewed_by);
        $this->assertNotNull($consultation->interviewed_at);
    }

    public function test_it_records_the_onset_and_the_evolution(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Toux',
                'reason' => '<p>Toux sèche nocturne.</p>',
                'symptom_onset' => 'Depuis 10 jours',
                'evolution' => ConsultationEvolution::Worsening->value,
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertSame('Depuis 10 jours', $consultation->symptom_onset);
        $this->assertSame(ConsultationEvolution::Worsening, $consultation->evolution);
    }

    /** Neither onset nor evolution is ever demanded. */
    public function test_the_onset_and_evolution_stay_optional(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Céphalées',
                'reason' => '<p>Céphalées frontales.</p>',
                'complete' => true,
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/examen");

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertNull($consultation->symptom_onset);
        $this->assertNull($consultation->evolution);
    }

    public function test_a_consultation_without_any_current_treatment_is_valid(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Plaie superficielle',
                'reason' => '<p>Coupure au doigt ce matin.</p>',
                'current_treatments' => [],
                'complete' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $orientation->consultation()->firstOrFail()->currentTreatments()->count());
    }

    public function test_it_records_the_treatments_the_patient_reports_with_their_posology(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Fièvre',
                'reason' => '<p>Fièvre depuis deux jours.</p>',
                'current_treatments' => [
                    ['medication_name' => 'Paracétamol', 'dosage' => '500 mg', 'frequency' => '2 fois/jour', 'duration' => 'Depuis 3 jours'],
                ],
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $treatment = $orientation->consultation()->firstOrFail()->currentTreatments()->sole();

        $this->assertSame('Paracétamol', $treatment->medication_name);
        $this->assertSame('2 fois/jour', $treatment->frequency);
        $this->assertSame('Depuis 3 jours', $treatment->duration);
    }

    /**
     * Known habitual treatments are shown so the doctor reads them rather
     * than re-typing them. They belong to the patient, not the consultation.
     */
    public function test_the_known_habitual_treatments_are_served_to_the_screen(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);

        PatientTreatment::query()->create([
            'patient_id' => $episode->patient_id,
            'medication_name' => 'Amlodipine',
            'dosage' => '5 mg',
            'frequency' => '1 fois/jour',
            'active' => true,
            'recorded_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/consultation")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('habitual_treatments', 1)
                ->where('habitual_treatments.0.medication_name', 'Amlodipine')
                ->where('habitual_treatments.0.dosage', '5 mg')
            );
    }

    public function test_a_reported_change_to_the_habitual_treatments_requires_its_description(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Contrôle',
                'reason' => '<p>Contrôle de routine.</p>',
                'known_treatment_change' => 'YES',
                'complete' => false,
            ])
            ->assertSessionHasErrors('known_treatment_change_notes');

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Contrôle',
                'reason' => '<p>Contrôle de routine.</p>',
                'known_treatment_change' => 'YES',
                'known_treatment_change_notes' => 'Arrêt de l’Amlodipine depuis 2 semaines.',
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Arrêt de l’Amlodipine depuis 2 semaines.',
            $orientation->consultation()->firstOrFail()->known_treatment_change_notes,
        );
    }

    /**
     * The heart of §8: an allergy revealed today is always kept on the
     * consultation, and reaches the permanent record only when the doctor
     * explicitly asks for it.
     */
    public function test_a_reported_allergy_never_reaches_the_permanent_record_on_its_own(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Éruption cutanée',
                'reason' => '<p>Éruption après prise d’antibiotique.</p>',
                'reported_allergies' => [
                    ['substance' => 'Amoxicilline', 'reaction' => 'Urticaire', 'promote_to_patient_record' => false],
                ],
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertSame('Amoxicilline', $consultation->reported_allergies[0]['substance']);
        $this->assertSame(
            0,
            $episode->patient->allergies()->count(),
            'The permanent record is never written without an explicit request.',
        );
    }

    public function test_a_reported_allergy_reaches_the_permanent_record_when_explicitly_asked(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Éruption cutanée',
                'reason' => '<p>Éruption après prise d’antibiotique.</p>',
                'reported_allergies' => [
                    ['substance' => 'Amoxicilline', 'reaction' => 'Urticaire', 'promote_to_patient_record' => true],
                ],
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $episode->patient->allergies()->count());
        // And the consultation keeps its own snapshot of what was said.
        $this->assertSame(
            'Amoxicilline',
            $orientation->consultation()->firstOrFail()->reported_allergies[0]['substance'],
        );
    }

    /**
     * Reporting is one permission, writing the permanent file is another.
     * A doctor without `patients.medical_history.manage` may still record
     * what the patient said.
     */
    public function test_promoting_to_the_permanent_record_requires_its_own_permission(): void
    {
        $doctor = $this->doctor(withMedicalHistoryManagement: false);
        [$episode, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'chief_complaint' => 'Éruption cutanée',
                'reason' => '<p>Éruption.</p>',
                'reported_allergies' => [
                    ['substance' => 'Amoxicilline', 'promote_to_patient_record' => true],
                ],
                'complete' => false,
            ])
            ->assertSessionHasErrors('reported_information');

        $this->assertSame(0, $episode->patient->allergies()->count());
    }

    /** Omitting a block never erases it (ADR-074). */
    public function test_omitting_a_block_leaves_what_was_already_reported_intact(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)->put($this->url($orientation), [
            'chief_complaint' => 'Fièvre',
            'reason' => '<p>Fièvre.</p>',
            'current_treatments' => [['medication_name' => 'Paracétamol']],
            'reported_allergies' => [['substance' => 'Pénicilline']],
            'complete' => false,
        ]);

        // A later save that carries neither block must not wipe them.
        $this->actingAs($doctor)->put($this->url($orientation), [
            'chief_complaint' => 'Fièvre persistante',
            'reason' => '<p>Fièvre toujours présente.</p>',
            'complete' => false,
        ])->assertSessionHasNoErrors();

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertSame('Fièvre persistante', $consultation->chief_complaint);
        $this->assertSame(1, $consultation->currentTreatments()->count());
        $this->assertSame('Pénicilline', $consultation->reported_allergies[0]['substance']);
    }

    /**
     * "Enregistrer" keeps the work without claiming the step is done;
     * "Enregistrer et continuer" validates it and moves to the examination.
     */
    public function test_saving_leaves_the_step_in_progress_and_continuing_validates_it(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $payload = [
            'chief_complaint' => 'Douleur thoracique',
            'reason' => '<p>Douleur thoracique à l’effort.</p>',
        ];

        $this->actingAs($doctor)->put($this->url($orientation), [...$payload, 'complete' => false]);

        $step = fn () => $orientation->consultation()->firstOrFail()->steps()
            ->where('step', ConsultationStep::Interview->value)->sole()->status;

        $this->assertSame(ConsultationStepStatus::InProgress, $step());

        $this->actingAs($doctor)
            ->put($this->url($orientation), [...$payload, 'complete' => true])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/examen");

        $this->assertSame(ConsultationStepStatus::Completed, $step());
    }

    /** The step's minimum is a chief complaint and a history — nothing more. */
    public function test_validating_the_step_requires_the_chief_complaint_and_the_history(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), ['reason' => '<p>Histoire.</p>', 'complete' => true])
            ->assertSessionHasErrors('chief_complaint');

        $this->actingAs($doctor)
            ->put($this->url($orientation), ['chief_complaint' => 'Douleur', 'complete' => true])
            ->assertSessionHasErrors('reason');

        // A draft save demands neither: work in progress is never blocked.
        $this->actingAs($doctor)
            ->put($this->url($orientation), ['chief_complaint' => '', 'reason' => '', 'complete' => false])
            ->assertSessionHasNoErrors();
    }

    private function url(EpisodeOrientation $orientation): string
    {
        return "/medicine/orientations/{$orientation->uuid}/interrogatoire";
    }

    private function doctor(bool $withMedicalHistoryManagement = true): User
    {
        $code = $withMedicalHistoryManagement ? 'MEDICINE' : 'MEDICINE_NO_HISTORY';
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => 'Médecine']);

        $permissions = [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view',
            'diagnoses.view', 'prescriptions.view',
        ];

        if ($withMedicalHistoryManagement) {
            $permissions[] = 'patients.medical_history.manage';
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
