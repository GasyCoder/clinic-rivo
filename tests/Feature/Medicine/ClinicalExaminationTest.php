<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ClinicalExamSystem;
use App\Enums\ClinicalSystemStatus;
use App\Enums\ConsciousnessStatus;
use App\Enums\ConsultationStep;
use App\Enums\ConsultationStepStatus;
use App\Enums\GeneralCondition;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\ClinicalExamination;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The structured clinical examination.
 *
 * The guarantee under test throughout is the one a single free-text field
 * could not give: a system nobody looked at is never reported as normal, and
 * an anomaly is never recorded without saying what it is.
 */
class ClinicalExaminationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_general_condition_and_consciousness(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'general_condition' => GeneralCondition::Fair->value,
                'consciousness_status' => ConsciousnessStatus::Normal->value,
                'general_observation' => 'Patient fatigué, autonome.',
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $examination = $this->examination($orientation);

        $this->assertSame(GeneralCondition::Fair, $examination->general_condition);
        $this->assertSame(ConsciousnessStatus::Normal, $examination->consciousness_status);
        $this->assertSame('Patient fatigué, autonome.', $examination->general_observation);
        $this->assertSame($doctor->id, $examination->examined_by);
        $this->assertNotNull($examination->examined_at);
    }

    /** "Autre" carries its own precision; the server requires it. */
    public function test_an_other_consciousness_requires_its_precision(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'consciousness_status' => ConsciousnessStatus::Other->value,
                'complete' => false,
            ])
            ->assertSessionHasErrors('consciousness_details');

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'consciousness_status' => ConsciousnessStatus::Other->value,
                'consciousness_details' => 'Obnubilé par moments',
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Obnubilé par moments', $this->examination($orientation)->consciousness_details);
    }

    public function test_it_records_a_system_examined_and_normal(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'systems' => [
                    ['system_code' => ClinicalExamSystem::Cardiovascular->value, 'status' => ClinicalSystemStatus::Normal->value],
                ],
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $finding = $this->examination($orientation)->findings()->sole();

        $this->assertSame(ClinicalExamSystem::Cardiovascular, $finding->system_code);
        $this->assertSame(ClinicalSystemStatus::Normal, $finding->status);
        // No description is demanded for a normal examination, and none is
        // invented either.
        $this->assertNull($finding->findings);
    }

    /**
     * A system nobody looked at stores no row. The absence is what carries
     * the meaning, and `systems()` reports it as NOT_EXAMINED.
     */
    public function test_a_system_left_unexamined_stores_no_finding(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'systems' => [
                    ['system_code' => ClinicalExamSystem::Cardiovascular->value, 'status' => ClinicalSystemStatus::Normal->value],
                    ['system_code' => ClinicalExamSystem::Neurological->value, 'status' => ClinicalSystemStatus::NotExamined->value],
                ],
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $examination = $this->examination($orientation);

        $this->assertSame(1, $examination->findings()->count());
        $this->assertSame(
            ClinicalSystemStatus::NotExamined,
            $examination->systems()->firstWhere('system', ClinicalExamSystem::Neurological)['status'],
        );
    }

    /**
     * The rule that makes the whole grid trustworthy: an unexamined system is
     * never silently promoted to normal — not by the store, not by the
     * projection, not by omitting it from the payload entirely.
     */
    public function test_an_unexamined_system_is_never_reported_as_normal(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        // Only one system is mentioned at all; the other eight are omitted.
        $this->actingAs($doctor)->put($this->url($orientation), [
            'systems' => [
                ['system_code' => ClinicalExamSystem::Respiratory->value, 'status' => ClinicalSystemStatus::Normal->value],
            ],
            'complete' => false,
        ]);

        $systems = $this->examination($orientation)->systems();

        $this->assertSame(count(ClinicalExamSystem::cases()), $systems->count());
        $this->assertSame(
            1,
            $systems->where('status', ClinicalSystemStatus::Normal)->count(),
            'Only the system the doctor examined may read NORMAL.',
        );
        $this->assertSame(
            count(ClinicalExamSystem::cases()) - 1,
            $systems->where('status', ClinicalSystemStatus::NotExamined)->count(),
        );
    }

    public function test_an_abnormal_system_without_findings_is_refused(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'systems' => [
                    ['system_code' => ClinicalExamSystem::Respiratory->value, 'status' => ClinicalSystemStatus::Abnormal->value, 'findings' => '   '],
                ],
                'complete' => false,
            ])
            ->assertSessionHasErrors('systems.0.findings');

        $this->assertNull(ClinicalExamination::query()->first());
    }

    public function test_an_abnormal_system_with_findings_is_recorded(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'systems' => [
                    ['system_code' => ClinicalExamSystem::Respiratory->value, 'status' => ClinicalSystemStatus::Abnormal->value, 'findings' => 'Râles crépitants base droite'],
                ],
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $finding = $this->examination($orientation)->findings()->sole();

        $this->assertSame(ClinicalSystemStatus::Abnormal, $finding->status);
        $this->assertSame('Râles crépitants base droite', $finding->findings);
    }

    public function test_it_records_the_complementary_notes_without_requiring_them(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        // The examination is documented by the grid alone: the free notes are
        // optional now that they are no longer the only record.
        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'systems' => [
                    ['system_code' => ClinicalExamSystem::Skin->value, 'status' => ClinicalSystemStatus::Normal->value],
                ],
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'clinical_exam' => '<p>Patient vu au lit, examen difficile</p>',
                'complete' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '<p>Patient vu au lit, examen difficile</p>',
            $orientation->consultation()->firstOrFail()->clinical_exam,
        );
    }

    /**
     * The examination is a corrigible statement, not an append-only act: the
     * doctor rewrites it as they go. Correcting an anomaly to normal drops
     * the findings, which described an anomaly that is no longer claimed.
     */
    public function test_an_existing_examination_is_corrected_in_place(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        $this->actingAs($doctor)->put($this->url($orientation), [
            'general_condition' => GeneralCondition::Altered->value,
            'systems' => [
                ['system_code' => ClinicalExamSystem::Digestive->value, 'status' => ClinicalSystemStatus::Abnormal->value, 'findings' => 'Défense épigastrique'],
            ],
            'complete' => false,
        ]);

        $this->actingAs($doctor)->put($this->url($orientation), [
            'general_condition' => GeneralCondition::Good->value,
            'systems' => [
                ['system_code' => ClinicalExamSystem::Digestive->value, 'status' => ClinicalSystemStatus::Normal->value, 'findings' => 'Défense épigastrique'],
            ],
            'complete' => false,
        ])->assertSessionHasNoErrors();

        $examination = $this->examination($orientation);
        $finding = $examination->findings()->sole();

        $this->assertSame(1, ClinicalExamination::query()->count(), 'One examination per consultation.');
        $this->assertSame(GeneralCondition::Good, $examination->general_condition);
        $this->assertSame(ClinicalSystemStatus::Normal, $finding->status);
        $this->assertNull($finding->findings);
    }

    /**
     * The step is validated by a real examination, not by prose: recording
     * the grid is enough, and recording nothing at all is not.
     */
    public function test_the_step_is_validated_by_the_examination_not_by_the_notes(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);

        // Nothing examined yet: validating the step is refused.
        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/steps", [
                'step' => ConsultationStep::ClinicalExam->value,
                'intent' => 'COMPLETE',
            ])
            ->assertSessionHasErrors('step');

        // The grid alone documents the examination — no notes written.
        $this->actingAs($doctor)
            ->put($this->url($orientation), [
                'general_condition' => GeneralCondition::Good->value,
                'systems' => [
                    ['system_code' => ClinicalExamSystem::Cardiovascular->value, 'status' => ClinicalSystemStatus::Normal->value],
                ],
                'complete' => true,
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/paraclinique");

        $consultation = $orientation->consultation()->firstOrFail();

        $this->assertSame('', (string) $consultation->clinical_exam);
        $this->assertSame(
            ConsultationStepStatus::Completed,
            $consultation->steps()->where('step', ConsultationStep::ClinicalExam->value)->sole()->status,
        );
    }

    public function test_a_doctor_without_the_update_permission_cannot_record_an_examination(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->medicineConsultation($doctor);
        $reader = $this->doctor(['medical_record.view', 'consultations.view']);

        $this->actingAs($reader)
            ->put($this->url($orientation), [
                'general_condition' => GeneralCondition::Good->value,
                'complete' => false,
            ])
            ->assertForbidden();

        $this->assertNull(ClinicalExamination::query()->first());
    }

    /** No vital sign belongs to this form: Soins records them once (ADR-054). */
    public function test_the_examination_carries_no_vital_sign(): void
    {
        $columns = Schema::getColumnListing('clinical_examinations');

        foreach (['blood_pressure_systolic', 'blood_pressure_diastolic', 'heart_rate', 'spo2', 'temperature', 'weight', 'height', 'bmi'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    private function url(EpisodeOrientation $orientation): string
    {
        return "/medicine/orientations/{$orientation->uuid}/examen-clinique";
    }

    private function examination(EpisodeOrientation $orientation): ClinicalExamination
    {
        return $orientation->consultation()->firstOrFail()->clinicalExamination()->firstOrFail();
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
            'diagnoses.view', 'prescriptions.view',
        ] as $name) {
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
