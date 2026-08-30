<?php

namespace Tests\Feature\Http;

use App\Models\BillableItem;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_every_clinical_and_billing_permission_sees_the_full_passage_detail(): void
    {
        $doctor = $this->userWithPermissions(['patients.view'], 'MEDICINE_AUTHOR');
        $viewer = $this->userWithPermissions([
            'patients.view', 'care.view', 'vitals.view',
            'medical_record.view', 'diagnoses.view', 'prescriptions.view',
            'billing.view',
        ]);
        $episode = $this->episodeWithFullClinicalTrail($doctor);

        $this->actingAs($viewer)->get("/passages/{$episode->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Episodes/Show')
                ->where('episode.episode_number', $episode->episode_number)
                ->where('capabilities.can_view_care', true)
                ->where('capabilities.can_view_medical_record', true)
                ->where('capabilities.can_view_diagnoses', true)
                ->where('capabilities.can_view_prescriptions', true)
                ->where('capabilities.can_view_billing', true)
                ->has('episode.orientations', 1)
                ->where('episode.orientations.0.destination_module', 'CARE')
                ->where('episode.care_record.blood_group', 'O+')
                ->has('episode.care_record.procedures', 1)
                ->has('episode.consultations', 1)
                ->where('episode.consultations.0.reason', 'Douleur abdominale')
                ->has('episode.consultations.0.diagnoses', 1)
                ->where('episode.consultations.0.diagnoses.0.description', 'Gastrite')
                ->has('episode.consultations.0.prescriptions', 1)
                ->has('episode.consultations.0.prescriptions.0.lines', 1)
                ->where('billing.items.0.description', 'Consultation de médecine générale')
                ->where('billing.items.0.invoice.invoice_number', 'AF-000001')
                ->where('billing.items.0.invoice.status', 'VALIDATED')
                ->where('billing.total_amount', '20000.00')
                ->where('billing.balance_amount', '20000.00')
            );
    }

    public function test_a_user_with_only_patients_view_sees_nothing_gated(): void
    {
        $doctor = $this->userWithPermissions(['patients.view'], 'MEDICINE_AUTHOR');
        $viewer = $this->userWithPermissions(['patients.view']);
        $episode = $this->episodeWithFullClinicalTrail($doctor);

        $this->actingAs($viewer)->get("/passages/{$episode->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Episodes/Show')
                ->has('episode.orientations', 1)
                ->where('episode.care_record', null)
                ->has('episode.consultations', 0)
                ->where('billing', null)
                ->where('capabilities.can_view_care', false)
                ->where('capabilities.can_view_billing', false)
            );
    }

    /**
     * diagnoses.view, prescriptions.view and vitals.view each gate a
     * narrower slice than medical_record.view/care.view — a viewer who can
     * open the dossier and the fiche Soins at all must still not see the
     * diagnoses, prescriptions or constants those three finer permissions
     * specifically own.
     */
    public function test_medical_record_and_care_view_alone_do_not_leak_diagnoses_prescriptions_or_vitals(): void
    {
        $doctor = $this->userWithPermissions(['patients.view'], 'MEDICINE_AUTHOR');
        $viewer = $this->userWithPermissions([
            'patients.view', 'care.view', 'medical_record.view',
        ]);
        $episode = $this->episodeWithFullClinicalTrail($doctor);

        $this->actingAs($viewer)->get("/passages/{$episode->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Episodes/Show')
                ->where('capabilities.can_view_diagnoses', false)
                ->where('capabilities.can_view_prescriptions', false)
                ->where('capabilities.can_view_vitals', false)
                ->missing('episode.care_record.blood_group')
                ->missing('episode.care_record.blood_pressure_systolic')
                ->has('episode.care_record.procedures', 1)
                ->has('episode.consultations', 1)
                ->where('episode.consultations.0.reason', 'Douleur abdominale')
                ->has('episode.consultations.0.diagnoses', 0)
                ->has('episode.consultations.0.prescriptions', 0)
            );
    }

    public function test_a_user_without_patients_view_is_forbidden(): void
    {
        $doctor = $this->userWithPermissions(['patients.view'], 'MEDICINE_AUTHOR');
        $episode = $this->episodeWithFullClinicalTrail($doctor);
        $stranger = $this->userWithPermissions(['care.view'], 'STRANGER');

        $this->actingAs($stranger)->get("/passages/{$episode->uuid}")->assertForbidden();
    }

    private function episodeWithFullClinicalTrail(User $doctor): Episode
    {
        $patient = Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'M-000001-01',
            'status' => 'OPEN',
            'priority' => 'NORMAL',
            'financial_mode' => 'SELF',
            'financial_context_completed_at' => now(),
            'financial_context_completed_by' => $doctor->id,
            'administrative_status' => 'IN_CARE',
            'started_at' => now(),
            'created_by' => $doctor->id,
        ]);
        EpisodeOrientation::create([
            'episode_id' => $episode->id,
            'source_module' => 'RECEPTION',
            'destination_module' => 'CARE',
            'status' => 'COMPLETED',
            'oriented_by' => $doctor->id,
            'oriented_at' => now(),
            'accepted_at' => now(),
            'completed_at' => now(),
        ]);
        $careRecord = CareRecord::create([
            'episode_id' => $episode->id,
            'blood_group' => 'O+',
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);
        $careCatalogItem = $this->careCatalogItem($doctor);
        $careRecord->procedures()->create([
            'catalog_item_id' => $careCatalogItem->id,
            'catalog_item_uuid' => $careCatalogItem->uuid,
            'procedure_code' => 'PANSEMENT-S',
            'procedure_name' => 'Pansement simple',
            'quantity' => 1,
            'performed_by' => $doctor->id,
            'performed_at' => now(),
        ]);

        $consultation = Consultation::create([
            'episode_id' => $episode->id,
            'doctor_id' => $doctor->id,
            'reason' => 'Douleur abdominale',
            'clinical_exam' => 'Abdomen souple, douleur épigastrique',
            'decision' => 'MEDICATION_PRESCRIPTION',
            'consulted_at' => now(),
        ]);
        Diagnosis::create([
            'consultation_id' => $consultation->id,
            'type' => 'FINAL',
            'description' => 'Gastrite',
            'recorded_by' => $doctor->id,
        ]);
        $prescription = Prescription::create([
            'consultation_id' => $consultation->id,
            'status' => 'ACTIVE',
            'prescribed_by' => $doctor->id,
            'prescribed_at' => now(),
        ]);
        PrescriptionLine::create([
            'prescription_id' => $prescription->id,
            'medication_name' => 'Oméprazole',
            'dosage' => '20 mg',
            'frequency' => '1x/jour',
            'duration' => '7 jours',
            'is_manual_entry' => true,
        ]);

        $item = BillableItem::create([
            'episode_id' => $episode->id,
            'source_module' => 'MEDICINE',
            'description' => 'Consultation de médecine générale',
            'quantity' => '1.00',
            'unit_price' => '20000.00',
            'total_amount' => '20000.00',
            'gross_amount' => '20000.00',
            'coverage_amount' => '0.00',
            'staff_covered_amount' => '0.00',
            'staff_block_credit_used' => '0.00',
            'patient_amount' => '20000.00',
            'currency' => 'MGA',
            'status' => 'INVOICED',
            'created_by' => $doctor->id,
        ]);
        $invoice = Invoice::create([
            'patient_id' => $patient->id,
            'episode_id' => $episode->id,
            'invoice_number' => 'AF-000001',
            'status' => 'VALIDATED',
            'currency' => 'MGA',
            'financial_mode' => 'SELF',
            'subtotal_amount' => '20000.00',
            'discount_amount' => '0.00',
            'coverage_amount' => '0.00',
            'staff_covered_amount' => '0.00',
            'staff_block_credit_used' => '0.00',
            'total_amount' => '20000.00',
            'paid_amount' => '0.00',
            'balance_amount' => '20000.00',
            'created_by' => $doctor->id,
        ]);
        $invoice->lines()->create([
            'billable_item_id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'line_total' => $item->patient_amount,
            'gross_line_total' => $item->gross_amount,
            'coverage_rate' => '0.00',
            'coverage_amount' => '0.00',
            'staff_covered_amount' => '0.00',
            'staff_block_credit_used' => '0.00',
            'status' => 'ACTIVE',
            'created_by' => $doctor->id,
        ]);

        return $episode->fresh();
    }

    private function careCatalogItem(User $actor): CatalogItem
    {
        return CatalogItem::create([
            'code' => 'PANSEMENT-S',
            'name' => 'Pansement simple',
            'type' => 'SERVICE',
            'module' => 'CARE',
            'unit' => 'soin',
            'billable' => true,
            'stockable' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function userWithPermissions(array $permissions, string $roleCode = 'VIEWER'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
