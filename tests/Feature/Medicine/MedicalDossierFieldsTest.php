<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\PatientAntecedentType;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\ConsultationCurrentTreatment;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\PatientAntecedent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fields of the clinic's paper DOSSIER MÉDICAL the application did not yet
 * collect: current treatments, the personal/familial split of antecedents,
 * and the birth place.
 */
class MedicalDossierFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_doctor_records_the_treatments_the_patient_already_takes(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
                'chief_complaint' => 'Douleur abdominale',
                'reason' => '<p>Céphalées</p>',
                'current_treatments' => [
                    ['medication_name' => 'Amlodipine', 'dosage' => '5 mg/j', 'notes' => 'depuis 2 ans'],
                    ['medication_name' => 'Metformine', 'dosage' => '850 mg x2'],
                ],
            ])
            ->assertRedirect();

        $treatments = ConsultationCurrentTreatment::query()->orderBy('position')->get();
        $this->assertCount(2, $treatments);
        $this->assertSame('Amlodipine', $treatments[0]->medication_name);
        $this->assertSame('5 mg/j', $treatments[0]->dosage);
        $this->assertSame('depuis 2 ans', $treatments[0]->notes);
        $this->assertSame(0, $treatments[0]->position);
        $this->assertSame('Metformine', $treatments[1]->medication_name);
        $this->assertNull($treatments[1]->notes);
        $this->assertSame($doctor->id, $treatments[0]->recorded_by);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/consultation")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('consultation.current_treatments', 2)
                ->where('consultation.current_treatments.0.medication_name', 'Amlodipine')
            );
    }

    /**
     * A statement of what the patient says they take is corrigible, unlike a
     * performed act: rewriting the list replaces it rather than appending.
     */
    public function test_resubmitting_replaces_the_declared_treatments(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        foreach ([['Amlodipine'], ['Metformine', 'Aspirine']] as $names) {
            $this->actingAs($doctor)
                ->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
                    'chief_complaint' => 'Douleur abdominale',
                    'reason' => '<p>Céphalées</p>',
                    'current_treatments' => array_map(
                        fn (string $name) => ['medication_name' => $name],
                        $names,
                    ),
                ])
                ->assertRedirect();
        }

        $this->assertSame(
            ['Metformine', 'Aspirine'],
            ConsultationCurrentTreatment::query()->orderBy('position')->pluck('medication_name')->all(),
        );
    }

    /**
     * Interrogatoire and Examen clinique are now two separately-saved steps
     * (each its own endpoint, its own Consultation column) — saving one can
     * no longer even carry the other's field, so this guarantee is
     * structural rather than a `array_key_exists` check to get right.
     */
    public function test_saving_the_clinical_exam_leaves_the_declared_treatments_untouched(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => '<p>Céphalées</p>',
            'current_treatments' => [['medication_name' => 'Amlodipine']],
        ])->assertRedirect();

        // A later save on the exam step must not silently wipe what was
        // declared during the interview.
        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => '<p>Nuque souple</p>',
        ])->assertRedirect();

        $this->assertSame(1, ConsultationCurrentTreatment::query()->count());
    }

    public function test_a_treatment_line_requires_a_name(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)
            ->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
                'chief_complaint' => 'Douleur abdominale',
                'reason' => '<p>Céphalées</p>',
                'current_treatments' => [['dosage' => '5 mg/j']],
            ])
            ->assertSessionHasErrors('current_treatments.0.medication_name');

        $this->assertSame(0, ConsultationCurrentTreatment::query()->count());
    }

    public function test_antecedents_are_recorded_as_personal_or_familial(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->normalMedicineConsultation($doctor);
        $patient = $episode->patient;

        $this->actingAs($doctor)
            ->post("/patients/{$patient->uuid}/antecedents", ['description' => 'Asthme depuis l’enfance'])
            ->assertRedirect();

        $this->actingAs($doctor)
            ->post("/patients/{$patient->uuid}/antecedents", [
                'description' => 'Diabète chez le père',
                'type' => PatientAntecedentType::Familial->value,
            ])
            ->assertRedirect();

        // No type means the patient's own history, as every row recorded
        // before this distinction existed.
        $this->assertSame(
            PatientAntecedentType::Personal,
            PatientAntecedent::query()->where('description', 'Asthme depuis l’enfance')->sole()->type,
        );
        $this->assertSame(
            PatientAntecedentType::Familial,
            PatientAntecedent::query()->where('description', 'Diabète chez le père')->sole()->type,
        );

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('antecedents', 2)
                ->has('familial_antecedents', 1)
                ->where('familial_antecedents.0.description', 'Diabète chez le père')
            );
    }

    public function test_an_unknown_antecedent_type_is_refused(): void
    {
        $doctor = $this->doctor();
        [$episode] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)
            ->post("/patients/{$episode->patient->uuid}/antecedents", [
                'description' => 'Test',
                'type' => 'HEREDITARY',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, PatientAntecedent::query()->count());
    }

    public function test_the_patient_record_keeps_a_birth_place(): void
    {
        $doctor = $this->doctor();
        [$episode] = $this->normalMedicineConsultation($doctor);
        $patient = $episode->patient;

        $this->actingAs($doctor)
            ->put("/patients/{$patient->uuid}", [
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'sex' => $patient->sex->value,
                'birth_date' => $patient->birth_date?->toDateString(),
                'age' => $patient->birth_date ? null : $patient->declared_age,
                'birth_place' => 'Ambondromamy',
            ])
            ->assertRedirect();

        $this->assertSame('Ambondromamy', Patient::query()->find($patient->id)->birth_place);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);
        $permissions = [
            'medical_record.view', 'consultations.view', 'consultations.create',
            'consultations.update', 'patients.view', 'patients.update',
            'patients.medical_history.view', 'patients.medical_history.manage',
        ];

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function normalMedicineConsultation(User $doctor): array
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
            'code' => 'CONSULT-GEN-'.uniqid(),
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
