<?php

namespace Tests\Feature\Medicine;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineParaclinicalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultation_and_examen_clinique_persist_to_the_same_field_via_two_steps(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);

        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/consultation", [
            'reason' => 'Douleur abdominale depuis 2 jours',
            'clinical_exam' => '',
        ])->assertRedirect();

        $this->actingAs($doctor)->put("/medicine/orientations/{$orientation->uuid}/consultation", [
            'reason' => 'Douleur abdominale depuis 2 jours',
            'clinical_exam' => 'Sensibilité en fosse iliaque droite.',
        ])->assertRedirect();

        $consultation = $orientation->consultation()->sole();
        $this->assertSame('Douleur abdominale depuis 2 jours', $consultation->reason);
        $this->assertSame('Sensibilité en fosse iliaque droite.', $consultation->clinical_exam);
        $this->assertSame(1, Consultation::query()->count());
    }

    public function test_a_doctor_requests_multiple_analyses_without_an_immediate_result(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->normalMedicineConsultation($doctor);
        $nfs = $this->labItem($doctor, 'NFS', 'NFS');
        $crp = $this->labItem($doctor, 'CRP', 'CRP');

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [
                ['catalog_item_uuid' => $nfs->uuid],
                ['catalog_item_uuid' => $crp->uuid],
            ],
            'notes' => 'Bilan infectieux',
            'continue_to_diagnosis' => true,
        ])->assertRedirect("/medicine/orientations/{$orientation->uuid}/diagnostic");

        $labRequest = LabRequest::query()->sole();
        $this->assertSame($episode->id, $labRequest->episode_id);
        $this->assertSame(2, $labRequest->items()->count());
        $this->assertSame('REQUESTED', $labRequest->displayStatus());
        $this->assertDatabaseCount('lab_request_items', 2);
        foreach ($labRequest->items as $item) {
            $this->assertNull($item->resulted_at);
        }

        // Médecine orientation stays open (parallel, not a handoff).
        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
        $labOrientation = EpisodeOrientation::query()->findOrFail($labRequest->lab_orientation_id);
        $this->assertSame('LABORATORY', $labOrientation->destination_module->value);
        $this->assertSame($episode->id, $labOrientation->episode_id);
    }

    public function test_a_lab_result_becomes_visible_to_medicine_once_entered(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);
        $nfs = $this->labItem($doctor, 'NFS', 'NFS');
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $nfs->uuid]],
        ]);
        $item = LabRequestItem::query()->sole();

        $labTech = $this->labTechnician();
        $this->actingAs($labTech)->post("/laboratory/items/{$item->uuid}/result", [
            'result_value' => 'Hb 13.2 g/dL, GB 7200/mm3',
        ])->assertRedirect();

        $this->assertNotNull($item->fresh()->resulted_at);

        $this->actingAs($doctor)
            ->get("/medicine/orientations/{$orientation->uuid}/paraclinique")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation.lab_requests.0.status', 'COMPLETED')
                ->where('consultation.lab_requests.0.items.0.result_value', 'Hb 13.2 g/dL, GB 7200/mm3')
            );
    }

    public function test_a_surgical_referral_reuses_surgical_request_and_orients_without_a_new_episode(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->normalMedicineConsultation($doctor);
        $intervention = CatalogItem::query()->create([
            'code' => 'SURG-APPENDIX',
            'name' => 'Appendicectomie',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Surgery,
            'unit' => 'intervention',
            'billable' => false,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/surgical-referrals", [
            'catalog_item_uuid' => $intervention->uuid,
            'diagnostic' => 'Appendicite aiguë',
            'indication' => 'Douleur FID, défense',
            'priority' => 'URGENT',
        ])->assertRedirect();

        $surgicalRequest = SurgicalRequest::query()->sole();
        $this->assertSame($episode->id, $surgicalRequest->episode_id);
        $this->assertSame('Appendicectomie', $surgicalRequest->procedure_name);
        $this->assertStringContainsString('Appendicite aiguë', $surgicalRequest->notes);
        $this->assertSame(1, Episode::query()->count());
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => 'SURGERY',
        ]);
    }

    public function test_referrals_to_maternity_hospitalization_transfer_and_pediatrics_orient_without_a_new_episode(): void
    {
        $doctor = $this->doctor();
        foreach (['MATERNITY', 'HOSPITALIZATION', 'TRANSFER', 'PEDIATRICS'] as $destination) {
            [$episode, $orientation] = $this->normalMedicineConsultation($doctor);

            $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/referrals", [
                'destination' => $destination,
                'reason' => "Motif : test\nDestination : {$destination}",
            ])->assertRedirect();

            $this->assertDatabaseHas('episode_orientations', [
                'episode_id' => $episode->id,
                'destination_module' => $destination,
            ]);
            $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
        }

        $this->assertSame(4, Episode::query()->count());
    }

    public function test_emergency_episode_can_receive_a_lab_request_without_being_blocked(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($patient, EpisodePriority::Emergency, $doctor);
        $orientation = $episode->orientations()->where('destination_module', 'MEDICINE')->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($orientation, $doctor);
        $nfs = $this->labItem($doctor, 'NFS', 'NFS');

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $nfs->uuid]],
        ])->assertRedirect();

        $this->assertSame(1, LabRequest::query()->count());
        $this->assertSame('IN_PROGRESS', $orientation->fresh()->status->value);
    }

    public function test_a_nurse_cannot_request_analyses_or_surgery(): void
    {
        $nurse = $this->nurse();
        $doctor = $this->doctor();
        [, $orientation] = $this->normalMedicineConsultation($doctor);
        $nfs = $this->labItem($doctor, 'NFS', 'NFS');

        $this->actingAs($nurse)->post("/medicine/orientations/{$orientation->uuid}/lab-requests", [
            'items' => [['catalog_item_uuid' => $nfs->uuid]],
        ])->assertForbidden();

        $this->assertDatabaseCount('lab_requests', 0);
    }

    private function doctor(): User
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);
        $permissions = [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'patients.medical_history.view', 'care.view', 'vitals.view',
            'laboratory_orders.create', 'laboratory_orders.view', 'laboratory_results.view',
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
}
