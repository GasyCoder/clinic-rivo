<?php

namespace Tests\Feature\Hospitalization;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
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

        $this->actingAs($nurse)->post("/hospitalisation/{$stay->uuid}/sortie", $this->discharge())
            ->assertForbidden();

        // A hospital stay is never a paraclinical-only visit: the final
        // diagnosis is required (ADR-094).
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/sortie", $this->discharge(['final_diagnosis' => '']))
            ->assertSessionHasErrors('final_diagnosis');

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/sortie", $this->discharge())
            ->assertSessionHasNoErrors();

        $stay->refresh();
        $this->assertSame(HospitalStayStatus::Discharged, $stay->status);
        $this->assertNull($stay->active_key);
        $this->assertSame(EpisodeOrientationStatus::Completed, $stay->episodeOrientation->status);

        $discharge = MedicalDischarge::query()->sole();
        $this->assertSame($stay->medical_discharge_id, $discharge->id);
        $this->assertSame('Gastro-entérite guérie', $discharge->final_diagnosis);

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
        $stay = HospitalStay::query()->sole();

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/sortie", $this->discharge([
            'type' => 'DECEASED',
            'patient_condition' => '',
            'discharge_prescription' => null,
            'recommendations' => null,
            'follow_up_at' => null,
            'death_occurred_at' => now()->subHour()->format('Y-m-d H:i'),
            'death_place' => 'Service de médecine',
            'death_causes' => 'Choc hypovolémique',
        ]))->assertRedirect(route('deaths.index'));

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

    private function requestHospitalization(User $doctor, EpisodeOrientation $orientation): void
    {
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();
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
