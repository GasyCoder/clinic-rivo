<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\MedicalDischargeType;
use App\Enums\MedicineForm;
use App\Enums\PrescriptionLineReviewStatus;
use App\Enums\PrescriptionStatus;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MedicalDischarge;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function doctor(array $permissions = [
        'medical_record.view',
        'consultations.view', 'consultations.create', 'consultations.update',
        'diagnoses.view', 'diagnoses.create', 'diagnoses.update',
        'prescriptions.view', 'prescriptions.create', 'prescriptions.update', 'prescriptions.cancel',
        'medicines.view', 'stock.availability.view',
        'medical_discharge.create', 'patients.view', 'patients.medical_history.view',
        'patients.medical_history.manage', 'care.view', 'vitals.view',
    ]): User
    {
        $role = Role::query()->create(['code' => 'MEDICINE', 'name' => 'Médecine']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => sprintf('A-26-%04d', Patient::query()->count() + 99),
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1992-05-12',
            'sex' => 'F',
            'phone' => '0320000000',
        ]);
    }

    private function medicineOrientation(User $doctor): EpisodeOrientation
    {
        $this->actingAs($doctor);
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient(), EpisodePriority::Emergency);

        return $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
    }

    private function stockedMedicine(User $actor, string $name = 'Paracétamol', int $quantity = 100): Medicine
    {
        $item = CatalogItem::query()->create([
            'code' => 'TEST-MED-'.(CatalogItem::query()->count() + 1),
            'name' => $name,
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'comprimé',
            'billable' => false,
            'stockable' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $medicine = Medicine::query()->create([
            'catalog_item_id' => $item->id,
            'generic_name' => $name,
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        MedicineLot::query()->create([
            'medicine_id' => $medicine->id,
            'lot_number' => 'TEST-LOT-'.(MedicineLot::query()->count() + 1),
            'expires_at' => now()->addYear()->toDateString(),
            'quantity_on_hand' => $quantity,
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return $medicine->load('catalogItem');
    }

    public function test_accepting_medicine_opens_one_consultation_and_is_idempotent_for_an_existing_queue_entry(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/accept")
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/dossier");

        $this->assertDatabaseHas('consultations', [
            'episode_id' => $orientation->episode_id,
            'episode_orientation_id' => $orientation->id,
            'doctor_id' => $doctor->id,
        ]);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);
        $this->assertSame(EpisodeMedicalStatus::InCare, $orientation->episode->fresh()->medical_status);

        $this->get("/medicine/orientations/{$orientation->uuid}")
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/dossier");

        $this->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Show')
                ->where('orientation.episode.uuid', $orientation->episode->uuid)
                ->where('consultation.doctor', $doctor->name)
                ->where('current_step', 'dossier')
                ->where('capabilities.can_discharge', true));

        // 'diagnostic' et 'decision' ne sont plus des écrans : ils redirigent
        // vers celui qui porte leur fonction (ADR-081, ADR-084).
        foreach (['consultation', 'examen', 'paraclinique', 'ordonnance', 'cloture'] as $step) {
            $this->get("/medicine/orientations/{$orientation->uuid}/{$step}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Medicine/Show')
                    ->where('current_step', $step));
        }

        $this->actingAs($doctor)
            ->post("/medicine/orientations/{$orientation->uuid}/accept")
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/dossier");

        $this->assertSame(1, Consultation::query()
            ->where('episode_orientation_id', $orientation->id)
            ->count());
    }

    public function test_doctor_can_save_consultation_append_diagnoses_and_create_an_ordered_prescription(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $medicine = $this->stockedMedicine($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Douleur abdominale aiguë',
            'current_treatments' => [],
        ])->assertRedirect();

        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => 'Sensibilité de la fosse iliaque droite.',
        ])->assertRedirect();

        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'HYPOTHESIS',
            'description' => 'Appendicite suspectée',
        ])->assertRedirect();

        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Colique abdominale sans signe de gravité',
        ])->assertRedirect();

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'continue_to_decision' => true,
            'lines' => [
                [
                    'medicine_uuid' => $medicine->catalogItem->uuid,
                    'quantity' => 6,
                    'dosage' => '500 mg',
                    'frequency' => '3 fois par jour',
                    'duration' => '3 jours',
                    'instructions' => 'Après le repas',
                ],
            ],
            // `decision` n'est plus une destination : « Enregistrer et
            // continuer » mène directement à la Clôture, sans payer la
            // redirection de l'URL héritée.
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/cloture");

        $consultation = $orientation->consultation()->firstOrFail();
        $this->assertSame('Douleur abdominale aiguë', $consultation->reason);
        $this->assertSame(2, $consultation->diagnoses()->count());

        $prescription = $consultation->prescriptions()->with('lines')->sole();
        $this->assertSame($doctor->id, $prescription->prescribed_by);
        $this->assertNotNull($prescription->prescribed_at);
        $this->assertSame('Paracétamol', $prescription->lines->sole()->medication_name);
        $this->assertSame(6, $prescription->lines->sole()->quantity);
        $this->assertSame(1, $prescription->lines->sole()->stockReservations()->count());
    }

    public function test_consultation_text_fields_keep_supported_formatting_and_remove_unsafe_markup(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => '<p onclick="alert(1)"><b>Douleur abdominale</b> <span style="background-color: yellow">depuis hier</span><script>alert(1)</script></p><ul><li>Fièvre</li><li>Nausées</li></ul>',
            'current_treatments' => [],
        ])->assertRedirect();

        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => '<p><b>Palpation</b></p><ol><li>Sensibilité FID</li><li>Défense absente</li></ol><img src=x onerror="alert(1)">',
        ])->assertRedirect();

        $expectedReason = '<p><strong>Douleur abdominale</strong> <mark>depuis hier</mark></p><ul><li>Fièvre</li><li>Nausées</li></ul>';
        $expectedExam = '<p><strong>Palpation</strong></p><ol><li>Sensibilité FID</li><li>Défense absente</li></ol>';
        $consultation = $orientation->consultation()->sole();
        $this->assertSame($expectedReason, $consultation->reason);
        $this->assertSame($expectedExam, $consultation->clinical_exam);

        $this->get("/medicine/orientations/{$orientation->uuid}/consultation")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.reason', $expectedReason)
                ->where('consultation.clinical_exam', $expectedExam));
    }

    public function test_consultation_reason_rejects_formatting_without_clinical_text(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->from("/medicine/orientations/{$orientation->uuid}/consultation")
            ->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
                'chief_complaint' => 'Douleur abdominale',
                'reason' => '<p><br></p><script>alert(1)</script>',
                'current_treatments' => [],
            ])
            ->assertRedirect("/medicine/orientations/{$orientation->uuid}/consultation")
            ->assertSessionHasErrors('reason');
    }

    /**
     * The conduite à tenir accepts the six real destinations and nothing
     * else. LABORATORY_TESTS, IMAGING, SIMPLE_TREATMENT,
     * MEDICATION_PRESCRIPTION and NURSING_CARE are inferred from the
     * doctor's actual acts (a lab request, an imaging request, a
     * prescription, a care order) elsewhere in the wizard, never from a raw
     * dropdown — so the endpoint must reject them outright rather than
     * record an orientation no destination will ever act on (ADR-084).
     */
    public function test_orientation_rejects_values_outside_the_six_destinations(): void
    {
        $doctor = $this->doctor([
            'consultations.view', 'consultations.create', 'consultations.update',
            'surgery.request', 'hospitalization.request', 'maternity.request',
            'transfer.request', 'pediatrics.request', 'medical_discharge.create',
        ]);

        foreach (['LABORATORY_TESTS', 'IMAGING', 'SIMPLE_TREATMENT', 'MEDICATION_PRESCRIPTION', 'NURSING_CARE'] as $decision) {
            $orientation = $this->medicineOrientation($doctor);
            $episode = $orientation->episode;
            $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

            $this->post("/medicine/orientations/{$orientation->uuid}/orientation", [
                'type' => $decision,
            ])->assertSessionHasErrors('type');

            $this->assertNull($orientation->consultation()->firstOrFail()->decision);
            $this->assertSame(2, $episode->orientations()->count());
            $this->assertSame('OPEN', $episode->fresh()->status->value);
        }

        $this->assertDatabaseCount('surgical_requests', 0);
        $this->assertDatabaseCount('medical_discharges', 0);
    }

    public function test_doctor_can_prepare_authorized_orientations_without_starting_destination_workflows(): void
    {
        $doctor = $this->doctor([
            'consultations.view', 'consultations.create', 'consultations.update',
            'surgery.request', 'hospitalization.request', 'maternity.request',
        ]);
        $cases = [
            ['type' => 'SURGERY', 'decision' => 'SURGERY', 'destination' => 'SURGERY'],
            ['type' => 'HOSPITALIZATION', 'decision' => 'HOSPITALIZATION', 'destination' => 'HOSPITALIZATION'],
            ['type' => 'MATERNITY', 'decision' => 'MATERNITY_REFERRAL', 'destination' => 'MATERNITY'],
        ];

        foreach ($cases as $case) {
            $orientation = $this->medicineOrientation($doctor);
            $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

            $this->post("/medicine/orientations/{$orientation->uuid}/orientation", [
                'type' => $case['type'],
            ])->assertSessionHasNoErrors();

            $consultation = $orientation->consultation()->firstOrFail();
            // Choosing is not transmitting: the destination module is still
            // untouched, and the orientation says so.
            $this->assertSame($case['decision'], $consultation->decision->value);
            $this->assertSame('SELECTED', $consultation->activeOrientation->status->value);
            $this->assertDatabaseMissing('episode_orientations', [
                'episode_id' => $orientation->episode_id,
                'destination_module' => $case['destination'],
            ]);
        }

        $this->assertDatabaseCount('surgical_requests', 0);
    }

    public function test_doctor_cannot_prepare_an_orientation_without_its_effective_permission(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->post("/medicine/orientations/{$orientation->uuid}/orientation", [
            'type' => 'SURGERY',
        ])->assertSessionHasErrors('type');

        $this->assertNull($orientation->consultation()->firstOrFail()->decision);
        $this->assertDatabaseCount('surgical_requests', 0);
    }

    /**
     * MedicineDossierPresenter reuses CareRecordReadModel (same one Surgery
     * and Anesthesia already read from, ADR-048) instead of re-deriving its
     * own constants projection, so a threshold changed once in the shared
     * *Assessment engines is reflected here without touching this file.
     */
    public function test_the_care_record_projection_carries_the_same_threshold_assessments_as_care_and_surgery(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);

        CareRecord::query()->create([
            'episode_id' => $orientation->episode_id,
            'blood_group' => 'O+',
            'blood_pressure_systolic' => 145,
            'blood_pressure_diastolic' => 95,
            'heart_rate' => 55,
            'spo2' => 92,
            'temperature_celsius' => '38.20',
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('care_record.read_only', true)
                ->where('care_record.blood_pressure_assessment.code', 'HIGH_STAGE_2')
                ->where('care_record.blood_pressure_assessment.tone', 'warning')
                ->where('care_record.heart_rate_assessment.code', 'LOW')
                ->where('care_record.heart_rate_assessment.tone', 'warning')
                ->where('care_record.spo2_assessment.code', 'VERY_LOW')
                ->where('care_record.spo2_assessment.tone', 'danger')
                ->where('care_record.temperature_assessment.code', 'FEVER')
                ->where('care_record.temperature_assessment.tone', 'warning')
            );
    }

    /**
     * Antecedents are a permanent Patient record (§19), not a Consultation
     * field: this proves one added through the generic PatientController
     * endpoint (patients.medical_history.manage) — by Médecine or any other
     * caller — is visible on the very next consultation dossier read,
     * without MedicineDossierPresenter duplicating any recording logic.
     */
    public function test_an_antecedent_added_through_the_generic_endpoint_is_visible_on_the_next_consultation(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->actingAs($doctor)
            ->post("/patients/{$orientation->episode->patient->uuid}/antecedents", [
                'description' => 'Hypertension artérielle connue',
            ])
            ->assertRedirect();

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_manage_medical_history', true)
                ->has('antecedents', 1)
                ->where('antecedents.0.description', 'Hypertension artérielle connue'));
    }

    private function catalogReviewer(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'ADMINISTRATION'], ['name' => 'Administration']);

        foreach (['catalog.items.view', 'catalog.items.create'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_doctor_can_add_a_manual_medicine_missing_from_the_pharmacy_catalog_without_blocking_the_prescription(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $medicine = $this->stockedMedicine($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Fièvre persistante',
            'current_treatments' => [],
        ])->assertRedirect();
        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => 'Examen sans particularité.',
        ])->assertRedirect();

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [
                [
                    'manual' => false,
                    'medicine_uuid' => $medicine->catalogItem->uuid,
                    'quantity' => 2,
                    'dosage' => '500 mg',
                    'frequency' => '3 fois par jour',
                ],
                [
                    'manual' => true,
                    'medication_name' => 'Amoxiclav 1g (hors référentiel)',
                    'quantity' => 14,
                    'dosage' => '1g',
                    'frequency' => '2 fois par jour',
                    'duration' => '7 jours',
                ],
            ],
        ])->assertRedirect();

        $prescription = $orientation->consultation()->firstOrFail()->prescriptions()->with('lines')->sole();
        $catalogLine = $prescription->lines->firstWhere('is_manual_entry', false);
        $manualLine = $prescription->lines->firstWhere('is_manual_entry', true);

        $this->assertNotNull($catalogLine);
        $this->assertSame(1, $catalogLine->stockReservations()->count());

        $this->assertNotNull($manualLine);
        $this->assertNull($manualLine->medicine_id);
        $this->assertSame('Amoxiclav 1g (hors référentiel)', $manualLine->medication_name);
        $this->assertSame(14, $manualLine->quantity);
        $this->assertSame(0, $manualLine->stockReservations()->count());
        $this->assertSame(PrescriptionLineReviewStatus::Pending, $manualLine->catalog_review_status);
        $this->assertNull($manualLine->catalog_reviewed_by);
        $this->assertNull($manualLine->catalog_review_note);

        // The doctor's own screen never receives a price for either kind of line.
        $this->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertInertia(fn ($page) => $page
                ->where('consultation.prescriptions.0.lines.1.medication_name', 'Amoxiclav 1g (hors référentiel)')
                ->where('consultation.prescriptions.0.lines.1.is_manual_entry', true)
                ->where('consultation.prescriptions.0.lines.1.catalog_review_status', 'PENDING')
                ->missing('options.medicines.0.price')
                ->missing('options.medicines.0.tariff')
                ->missing('options.medicines.0.amount'));
    }

    public function test_manual_prescription_line_requires_a_medication_name(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Douleur',
            'current_treatments' => [],
        ]);
        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => 'RAS',
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [
                ['manual' => true, 'quantity' => 1],
            ],
        ])->assertSessionHasErrors(['lines.0.medication_name']);

        $this->assertSame(0, Prescription::query()->count());
    }

    public function test_doctor_can_edit_a_manual_prescription_lines_quantity_and_name_freely(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Douleur',
            'current_treatments' => [],
        ]);
        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => 'RAS',
        ]);
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'manual' => true,
                'medication_name' => 'Doliprane 500',
                'quantity' => 6,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ]);
        $prescription = Prescription::query()->with('lines')->sole();
        $line = $prescription->lines->sole();

        $this->put("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}", [
            'lines' => [[
                'id' => $line->id,
                'quantity' => 20,
                'medication_name' => 'Doliprane 500 (corrigé)',
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ])->assertRedirect();

        $line->refresh();
        $this->assertSame(20, $line->quantity);
        $this->assertSame('Doliprane 500 (corrigé)', $line->medication_name);
        $this->assertSame(0, $line->stockReservations()->count());
    }

    public function test_cancelling_a_prescription_with_a_manual_line_releases_nothing_and_does_not_error(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Douleur',
            'current_treatments' => [],
        ]);
        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => 'RAS',
        ]);
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'manual' => true,
                'medication_name' => 'Doliprane 500',
                'quantity' => 6,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ]);
        $prescription = Prescription::query()->sole();

        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}/cancel", [
            'reason' => 'Erreur de saisie',
        ])->assertRedirect();

        $this->assertSame(PrescriptionStatus::Cancelled, $prescription->fresh()->status);
    }

    public function test_a_permitted_account_can_resolve_a_pending_unlisted_medicine_request(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Douleur',
            'current_treatments' => [],
        ]);
        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => 'RAS',
        ]);
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'manual' => true,
                'medication_name' => 'Amoxiclav 1g',
                'quantity' => 14,
                'dosage' => '1g',
                'frequency' => '2 fois par jour',
            ]],
        ]);
        $line = Prescription::query()->sole()->lines->sole();
        $reviewer = $this->catalogReviewer();

        $this->actingAs($reviewer)
            ->get('/administration/catalog')
            ->assertInertia(fn ($page) => $page
                ->where('pendingMedicines.0.medication_name', 'Amoxiclav 1g')
                ->where('pendingMedicines.0.quantity', 14));

        $this->actingAs($reviewer)
            ->post("/administration/catalog/pending-medicines/{$line->id}/review", [
                'note' => 'Ajouté au référentiel sous MED-0231.',
            ])
            ->assertRedirect();

        $line->refresh();
        $this->assertSame(PrescriptionLineReviewStatus::Resolved, $line->catalog_review_status);
        $this->assertSame($reviewer->id, $line->catalog_reviewed_by);
        $this->assertSame('Ajouté au référentiel sous MED-0231.', $line->catalog_review_note);
        $this->assertNotNull($line->catalog_reviewed_at);
    }

    public function test_an_unpermitted_account_cannot_resolve_a_pending_unlisted_medicine_request(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->put("/medicine/orientations/{$orientation->uuid}/interrogatoire", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Douleur',
            'current_treatments' => [],
        ]);
        $this->put("/medicine/orientations/{$orientation->uuid}/examen-clinique", [
            'clinical_exam' => 'RAS',
        ]);
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'manual' => true,
                'medication_name' => 'Amoxiclav 1g',
                'quantity' => 14,
                'dosage' => '1g',
                'frequency' => '2 fois par jour',
            ]],
        ]);
        $line = Prescription::query()->sole()->lines->sole();

        // The prescribing doctor holds prescriptions.* but not catalog.items.create.
        $this->actingAs($doctor)
            ->post("/administration/catalog/pending-medicines/{$line->id}/review", [
                'note' => 'Traité',
            ])
            ->assertForbidden();

        $this->assertSame(PrescriptionLineReviewStatus::Pending, $line->fresh()->catalog_review_status);
    }

    public function test_doctor_can_cancel_a_diagnosis_without_deleting_the_clinical_trace(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'HYPOTHESIS',
            'description' => 'Diagnostic saisi par erreur',
        ])->assertRedirect();

        $diagnosis = Diagnosis::query()->sole();

        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses/cancel", [
            'diagnosis_id' => $diagnosis->id,
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/examen");

        $this->assertDatabaseHas('diagnoses', [
            'id' => $diagnosis->id,
            'description' => 'Diagnostic saisi par erreur',
        ]);
        $this->assertDatabaseHas('diagnosis_cancellations', [
            'diagnosis_id' => $diagnosis->id,
            'reason' => 'Annulation par l’auteur de la saisie.',
            'cancelled_by' => $doctor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cancel',
            'entity_type' => Diagnosis::class,
            'entity_id' => $diagnosis->id,
            'reason' => 'Annulation par l’auteur de la saisie.',
        ]);

        $this->get("/medicine/orientations/{$orientation->uuid}/examen")
            ->assertInertia(fn ($page) => $page
                ->where('consultation.diagnoses.0.cancelled', true)
                ->where('consultation.diagnoses.0.can_cancel', false));
    }

    public function test_doctor_can_rectify_own_diagnosis_without_overwriting_the_original(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'HYPOTHESIS',
            'description' => 'Gastrite suspectée',
        ]);
        $original = Diagnosis::query()->sole();

        $this->put("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'diagnosis_id' => $original->id,
            'type' => 'FINAL',
            'description' => 'Gastrite aiguë confirmée',
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/examen");

        $replacement = Diagnosis::query()->where('id', '!=', $original->id)->sole();
        $this->assertSame('Gastrite suspectée', $original->fresh()->description);
        $this->assertSame('Gastrite aiguë confirmée', $replacement->description);
        $this->assertSame('FINAL', $replacement->type->value);
        $this->assertDatabaseHas('diagnosis_cancellations', [
            'diagnosis_id' => $original->id,
            'replacement_diagnosis_id' => $replacement->id,
            'reason' => 'Rectification par l’auteur de la saisie.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update',
            'entity_type' => Diagnosis::class,
            'entity_id' => $original->id,
            'reason' => 'Rectification par l’auteur de la saisie.',
        ]);

        $this->get("/medicine/orientations/{$orientation->uuid}/examen")
            ->assertInertia(fn ($page) => $page
                ->where('consultation.diagnoses.0.correction', true)
                ->where('consultation.diagnoses.0.can_edit', false)
                ->where('consultation.diagnoses.1.description', 'Gastrite aiguë confirmée')
                ->where('consultation.diagnoses.1.can_edit', true));
    }

    public function test_diagnosis_cancellation_requires_the_update_permission(): void
    {
        $doctor = $this->doctor([
            'consultations.view', 'consultations.create',
            'diagnoses.view', 'diagnoses.create',
        ]);
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Diagnostic protégé',
        ]);

        $diagnosis = Diagnosis::query()->sole();

        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses/cancel", [
            'diagnosis_id' => $diagnosis->id,
        ])->assertForbidden();

        $this->put("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'diagnosis_id' => $diagnosis->id,
            'type' => 'FINAL',
            'description' => 'Tentative de modification',
        ])->assertForbidden();

        $this->assertDatabaseCount('diagnosis_cancellations', 0);
    }

    public function test_a_doctor_cannot_cancel_a_diagnosis_recorded_by_another_doctor(): void
    {
        $doctor = $this->doctor();
        $otherDoctor = User::factory()->create(['role_id' => $doctor->role_id]);
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $diagnosis = $orientation->consultation()->firstOrFail()->diagnoses()->create([
            'type' => 'FINAL',
            'description' => 'Diagnostic du confrère',
            'recorded_by' => $otherDoctor->id,
        ]);

        $this->post("/medicine/orientations/{$orientation->uuid}/diagnoses/cancel", [
            'diagnosis_id' => $diagnosis->id,
        ])->assertSessionHasErrors('diagnosis_id');

        $this->put("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'diagnosis_id' => $diagnosis->id,
            'type' => 'FINAL',
            'description' => 'Modification interdite',
        ])->assertSessionHasErrors('diagnosis_id');

        $this->assertDatabaseCount('diagnosis_cancellations', 0);
        $this->assertDatabaseCount('diagnoses', 1);
    }

    /**
     * L'ADR-084 était vérifiée sur les données mais jamais sur l'écran.
     *
     * `MedicineDossierPresenter` conservait `medicalDischarge === null` dans
     * son calcul de `$isActive` : enregistrer une sortie reverrouillait donc
     * tout le dossier — interrogatoire, examen, prescription — alors que la
     * consultation était encore `IN_PROGRESS` et qu'aucune étape n'avait été
     * validée. C'est précisément le trajet que l'ADR-084 a supprimé.
     */
    public function test_recording_a_discharge_leaves_the_consultation_writable(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => MedicalDischargeType::Normal->value,
            'final_diagnosis' => 'État clinique stable',
            'patient_condition' => 'Patient conscient et stable au départ.',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/dossier")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_update_consultation', true)
                ->where('capabilities.can_resolve_step', true)
                ->where('capabilities.can_complete_consultation', true));
    }

    public function test_medical_discharge_completes_only_the_clinical_orientation_and_never_collects_payment(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $response = $this->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => MedicalDischargeType::Normal->value,
            'final_diagnosis' => 'État clinique stable',
            'patient_condition' => 'Patient conscient et stable au départ.',
            'recommendations' => 'Consulter en cas d’aggravation.',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]);

        $response->assertSessionHasNoErrors();

        $freshOrientation = $orientation->fresh();
        $episode = Episode::query()->findOrFail($orientation->episode_id);
        // Recording the discharge no longer ends the encounter (ADR-084):
        // the doctor can still prescribe, print and check the file. The
        // Médecine orientation and the episode statuses move at the Clôture.
        $this->assertSame(EpisodeOrientationStatus::InProgress, $freshOrientation->status);
        $this->assertNotSame(EpisodeMedicalStatus::MedicallyDischarged, $episode->medical_status);
        $this->assertSame(EpisodeStatus::Open, $episode->status);
        $this->assertNull($episode->ended_at);
        $this->assertSame(
            'DISCHARGE',
            $freshOrientation->consultation->activeOrientation->type->value,
        );
        $this->assertDatabaseHas('medical_discharges', [
            'episode_id' => $episode->id,
            'type' => MedicalDischargeType::Normal->value,
            'created_by' => $doctor->id,
        ]);
        $this->assertDatabaseHas('diagnoses', [
            'consultation_id' => $freshOrientation->consultation->id,
            'type' => 'FINAL',
            'description' => 'État clinique stable',
        ]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_closure_step_exposes_the_full_recorded_discharge_including_prescription_and_observations(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => MedicalDischargeType::Normal->value,
            'final_diagnosis' => 'État clinique stable',
            'patient_condition' => 'Patient conscient et stable au départ.',
            'discharge_prescription' => 'Ceftriaxone 1 g — 3×/jour — 7 jours',
            'recommendations' => 'Consulter en cas d’aggravation.',
            'observations' => 'Famille informée de la sortie.',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]);

        $this->get("/medicine/orientations/{$orientation->uuid}/cloture")
            ->assertInertia(fn ($page) => $page
                ->where('medical_discharge.discharge_prescription', 'Ceftriaxone 1 g — 3×/jour — 7 jours')
                ->where('medical_discharge.observations', 'Famille informée de la sortie.')
                ->where('medical_discharge.recommendations', 'Consulter en cas d’aggravation.'));
    }

    public function test_doctor_can_print_an_active_prescription_with_the_patient_and_passage_numbers(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $medicine = $this->stockedMedicine($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [[
                'manual' => false,
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 3,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ]);
        $prescription = Prescription::query()->sole();
        $patient = $orientation->episode->patient;

        $this->get("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}/print")
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/PrescriptionPrint')
                ->where('patient.patient_number', $patient->patient_number)
                ->where('episode.episode_number', $orientation->episode->episode_number)
                ->where('prescription.lines.0.medication_name', 'Paracétamol')
                ->where('prescription.lines.0.quantity', 3)
                ->missing('prescription.lines.0.price')
                ->missing('prescription.lines.0.amount'));
    }

    public function test_printing_a_prescription_through_an_unrelated_orientation_is_rejected(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $otherOrientation = $this->medicineOrientation($doctor);
        $medicine = $this->stockedMedicine($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [['manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 1, 'dosage' => '500 mg', 'frequency' => '3 fois par jour']],
        ]);
        $prescription = Prescription::query()->sole();

        $this->get("/medicine/orientations/{$otherOrientation->uuid}/prescriptions/{$prescription->uuid}/print")
            ->assertNotFound();
    }

    public function test_a_cancelled_prescription_cannot_be_printed(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $medicine = $this->stockedMedicine($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [['manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 1, 'dosage' => '500 mg', 'frequency' => '3 fois par jour']],
        ]);
        $prescription = Prescription::query()->sole();
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}/cancel", [
            'reason' => 'Erreur de saisie',
        ]);

        $this->get("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}/print")
            ->assertStatus(409);
    }

    public function test_printing_a_prescription_requires_prescriptions_view_permission(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $medicine = $this->stockedMedicine($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");
        $this->post("/medicine/orientations/{$orientation->uuid}/prescriptions", [
            'lines' => [['manual' => false, 'medicine_uuid' => $medicine->catalogItem->uuid, 'quantity' => 1, 'dosage' => '500 mg', 'frequency' => '3 fois par jour']],
        ]);
        $prescription = Prescription::query()->sole();

        $unauthorizedRole = Role::query()->create(['code' => 'MEDICINE_NO_PRESCRIPTIONS_VIEW', 'name' => 'Médecine sans prescriptions.view']);
        $unauthorized = User::factory()->create(['role_id' => $unauthorizedRole->id]);

        $this->actingAs($unauthorized)
            ->get("/medicine/orientations/{$orientation->uuid}/prescriptions/{$prescription->uuid}/print")
            ->assertForbidden();
    }

    public function test_transfer_and_death_require_the_specific_information_from_the_client_forms(): void
    {
        $doctor = $this->doctor();
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => MedicalDischargeType::Transfer->value,
            'final_diagnosis' => 'Traumatisme sévère',
            'patient_condition' => 'État nécessitant un plateau technique supérieur.',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('transfer_destination');

        $this->assertDatabaseCount('medical_discharges', 0);
        $this->assertSame(EpisodeOrientationStatus::InProgress, $orientation->fresh()->status);

        // ADR-107 (amendement) — un décès se prononce sans son détail : l'heure,
        // le lieu et les causes s'établissent dans le registre des décès, qui
        // les exige pour l'acte de constatation.
        $this->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => MedicalDischargeType::Deceased->value,
            'final_diagnosis' => 'Arrêt cardio-respiratoire',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();

        $discharge = MedicalDischarge::query()->sole();
        $this->assertNull($discharge->death_occurred_at);
        $this->assertNull($discharge->death_place);
        $this->assertNull($discharge->death_causes);
    }

    public function test_medical_discharge_permission_is_enforced_by_laravel(): void
    {
        $doctor = $this->doctor([
            'consultations.view', 'consultations.create', 'consultations.update',
        ]);
        $orientation = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/accept");

        $this->post("/medicine/orientations/{$orientation->uuid}/discharge", [
            'type' => MedicalDischargeType::Normal->value,
            'final_diagnosis' => 'Diagnostic',
            'patient_condition' => 'Stable',
            'discharged_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertForbidden();

        $this->assertDatabaseCount('medical_discharges', 0);
    }

    public function test_prescription_cancellation_cannot_cross_another_orientation(): void
    {
        $doctor = $this->doctor();
        $first = $this->medicineOrientation($doctor);
        $medicine = $this->stockedMedicine($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$first->uuid}/accept");
        $this->post("/medicine/orientations/{$first->uuid}/prescriptions", [
            'lines' => [[
                'medicine_uuid' => $medicine->catalogItem->uuid,
                'quantity' => 1,
                'dosage' => '500 mg',
                'frequency' => '3 fois par jour',
            ]],
        ]);
        $prescription = Prescription::query()->sole();

        $second = $this->medicineOrientation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$second->uuid}/accept");

        $this->post("/medicine/orientations/{$second->uuid}/prescriptions/{$prescription->uuid}/cancel", [
            'chief_complaint' => 'Douleur abdominale',
            'reason' => 'Tentative hors dossier',
        ])->assertForbidden();

        $this->assertSame('ACTIVE', $prescription->fresh()->status->value);
    }
}
