<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequestItem;
use App\Models\LabRequest;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineWizardRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_paraclinique_step_exposes_lab_and_imaging_catalog_and_capabilities(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);
        $this->labItem($doctor, 'NFS', 'NFS');
        $this->imagingItem($doctor, 'ECG', 'ECG');

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Show')
                ->where('capabilities.can_create_lab_request', true)
                ->where('capabilities.can_view_lab_requests', true)
                ->where('capabilities.can_create_imaging_request', true)
                ->where('capabilities.can_view_imaging_requests', true)
                ->has('options.lab_catalog', 1)
                ->has('options.imaging_catalog', 1)
            );
    }

    public function test_a_doctor_requests_and_records_an_imaging_exam(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->normalMedicineConsultation($doctor);
        $ecg = $this->imagingItem($doctor, 'ECG', 'ECG');

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests", [
            'items' => [['catalog_item_uuid' => $ecg->uuid]],
            'notes' => 'Douleur thoracique',
        ])->assertRedirect();

        $item = ImagingRequestItem::query()->sole();
        $this->assertSame($episode->id, $item->imagingRequest->episode_id);
        $this->assertNull($item->resulted_at);

        // No cross-module orientation is created for imaging (no workspace exists yet).
        $this->assertSame(1, EpisodeOrientation::query()->count());

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/imaging-requests/{$item->uuid}/result", [
            'result_value' => 'Rythme sinusal régulier, pas de trouble de repolarisation.',
        ])->assertRedirect();

        $this->assertNotNull($item->fresh()->resulted_at);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.imaging_requests.0.status', 'COMPLETED')
                ->where('consultation.imaging_requests.0.items.0.result_value', 'Rythme sinusal régulier, pas de trouble de repolarisation.')
            );
    }

    public function test_ordonnance_step_exposes_care_order_tab_data(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/ordonnance")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Show')
                ->where('capabilities.can_view_care_orders', true)
                ->where('capabilities.can_create_care_order', true)
                ->has('consultation.care_orders')
                ->has('options.care_order_catalog')
            );
    }

    public function test_decision_step_exposes_all_referral_capabilities_and_surgery_catalog(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/decision")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Show')
                ->where('capabilities.can_discharge', true)
                ->where('capabilities.can_request_surgery', true)
                ->where('capabilities.can_request_maternity', true)
                ->where('capabilities.can_request_hospitalization', true)
                ->where('capabilities.can_request_transfer', true)
                ->where('capabilities.can_request_pediatrics', true)
                ->has('options.surgery_catalog')
                ->has('options.referral_destinations', 4)
            );
    }

    public function test_a_nurse_cannot_open_the_medicine_wizard(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($nurse)
            ->get("/medicine/orientations/{$orientation->uuid}/decision")
            ->assertForbidden();
    }

    public function test_consultation_reason_is_never_seeded_from_the_orientation_routing_note(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $orientation = $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $episode,
            CatalogModule::Care,
            CatalogModule::Medicine,
            $doctor,
            'Orientation vers Médecine selon le parcours planifié.',
        );

        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);

        $reason = Consultation::query()->sole()->reason;
        $this->assertStringNotContainsString('parcours planifié', $reason);
    }

    public function test_continue_is_only_offered_when_a_clinical_element_is_genuinely_pending(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/decision")
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_defer_decision', false)
                ->where('pending_reasons', [])
            );

        $nfs = $this->labItem($doctor, 'NFS', 'NFS');
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $nfs->uuid]],
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/decision")
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_defer_decision', true)
                ->where('pending_reasons.0', '1 analyse en attente de résultat')
            );

        $item = LabRequest::query()->sole()->items()->sole();
        $this->actingAs($this->labTechnician())->post("/laboratory/items/{$item->uuid}/result", [
            'result_value' => 'Hb 13.2 g/dL',
        ]);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/decision")
            ->assertInertia(fn ($page) => $page
                ->where('capabilities.can_defer_decision', false)
                ->where('pending_reasons', [])
            );
    }

    public function test_dossier_source_module_reflects_the_real_arrival_pathway(): void
    {
        $doctor = $this->doctor();
        [, $direct] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$direct->uuid}/dossier")
            ->assertInertia(fn ($page) => $page->where('orientation.source_module', 'RECEPTION'));

        $patient = $this->patient();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $fromCare = $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $episode,
            CatalogModule::Care,
            CatalogModule::Medicine,
            $doctor,
            'Orientation vers Médecine selon le parcours planifié.',
        );
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($fromCare, $doctor);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$fromCare->uuid}/dossier")
            ->assertInertia(fn ($page) => $page->where('orientation.source_module', 'CARE'));
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);
        $permissions = [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view',
            'laboratory_orders.create', 'laboratory_orders.view', 'laboratory_results.view',
            'imaging_orders.create', 'imaging_orders.view', 'imaging_results.create',
            'care_orders.create', 'care_orders.view',
            'surgery.request', 'hospitalization.request', 'maternity.request',
            'transfer.request', 'pediatrics.request', 'medical_discharge.create',
        ];

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function nurse(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'NURSE'], ['name' => 'Soins']);
        $permission = Permission::query()->firstOrCreate(['name' => 'care.view']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function labTechnician(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'LABORATORY'], ['name' => 'Laboratoire']);
        foreach (['laboratory_results.create', 'laboratory_results.view'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    /** @return array{0: Episode, 1: EpisodeOrientation} */
    private function normalMedicineConsultation(User $doctor): array
    {
        $patient = $this->patient();
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $consultationItem = CatalogItem::query()->create([
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
            'catalog_item_uuid' => $consultationItem->uuid,
            'quantity' => 1,
        ]], $doctor);
        $medicineOrientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($medicineOrientation, $doctor);

        return [$episode->fresh(), $medicineOrientation->fresh()];
    }

    private function labItem(User $actor, string $code, string $name): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Laboratory,
            'unit' => 'analyse',
            'billable' => false,
            'stockable' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function imagingItem(User $actor, string $code, string $name): CatalogItem
    {
        return CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Imaging,
            'unit' => 'examen',
            'billable' => false,
            'stockable' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
