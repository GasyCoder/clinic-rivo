<?php

namespace Tests\Feature\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\CareRecord;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PreanestheticAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic']);
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ProfessionalProfileSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function surgeon(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', 'SURGERY')->value('id'),
        ]);
    }

    private function anesthetist(): User
    {
        $profile = ProfessionalProfile::query()->where('code', 'ANESTHETIST')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
            'professional_profile_id' => $profile->id,
        ]);

        $user->permissions()->syncWithoutDetaching(
            $profile->recommendedPermissions
                ->mapWithKeys(fn (Permission $permission) => [
                    $permission->id => ['effect' => 'allow'],
                ])
                ->all(),
        );

        return $user->fresh();
    }

    private function surgicalRequest(User $surgeon): SurgicalRequest
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Soa',
            'last_name' => 'Rasoa',
            'birth_date' => '1988-03-10',
            'sex' => 'F',
        ]);
        $episode = Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
        ]);

        return $episode->surgicalRequests()->create([
            'requested_by' => $surgeon->id,
            'created_by' => $surgeon->id,
            'surgeon_id' => $surgeon->id,
            'status' => SurgicalRequestStatus::Scheduled,
            'procedure_name' => 'Césarienne',
            'scheduled_at' => now()->addDay(),
        ]);
    }

    public function test_surgery_account_can_open_the_case_but_cannot_write_the_anesthetic_assessment(): void
    {
        $surgeon = $this->surgeon();
        $request = $this->surgicalRequest($surgeon);

        $this->actingAs($surgeon)
            ->get("/surgery/{$request->uuid}")
            ->assertOk();

        $this->actingAs($surgeon)
            ->post("/surgery/{$request->uuid}/anesthesia", [
                'consultation_data' => ['admission_reason' => 'Intervention programmée'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('anesthesia_records', 0);
    }

    public function test_anesthetist_can_complete_validate_and_lock_the_structured_assessment(): void
    {
        $surgeon = $this->surgeon();
        $anesthetist = $this->anesthetist();
        $request = $this->surgicalRequest($surgeon);

        $this->actingAs($anesthetist)
            ->post("/surgery/{$request->uuid}/anesthesia", [
                'consultation_data' => [
                    'admission_reason' => 'Intervention programmée',
                    'blood_pressure_systolic' => 120,
                    'blood_pressure_diastolic' => 80,
                    'medical_conditions' => ['HYPERTENSION'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $record = $request->fresh()->anesthesiaRecord;
        $this->assertSame($anesthetist->id, $record->anesthetist_id);
        $this->assertSame('Intervention programmée', $record->consultation_data['admission_reason']);

        $this->actingAs($anesthetist)
            ->post("/surgery/{$request->uuid}/anesthesia/{$record->id}/assessment/validate")
            ->assertSessionHasErrors('assessment');

        $this->actingAs($anesthetist)
            ->put("/surgery/{$request->uuid}/anesthesia/{$record->id}", [
                'paraclinical_data' => [
                    'blood_group' => 'O',
                    'rhesus' => 'POSITIVE',
                    'glasgow_eye' => 4,
                    'glasgow_verbal' => 5,
                    'glasgow_motor' => 6,
                    'apfel_score' => 2,
                    'surgery_authorized' => true,
                    'asa_class' => 'ASA II',
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($anesthetist)
            ->post("/surgery/{$request->uuid}/anesthesia/{$record->id}/assessment/validate")
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame($anesthetist->id, $record->assessment_validated_by);
        $this->assertNotNull($record->assessment_validated_at);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'surgery',
            'action' => 'update',
            'entity_id' => $record->id,
        ]);

        $this->actingAs($anesthetist)
            ->put("/surgery/{$request->uuid}/anesthesia/{$record->id}", [
                'consultation_data' => ['admission_reason' => 'Altération'],
            ])
            ->assertSessionHasErrors('assessment');
        $this->assertSame('Intervention programmée', $record->fresh()->consultation_data['admission_reason']);

        $this->actingAs($anesthetist)
            ->put("/surgery/{$request->uuid}/anesthesia/{$record->id}", [
                'notes' => 'Conduite peropératoire encore modifiable',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('Conduite peropératoire encore modifiable', $record->fresh()->notes);
    }

    public function test_consultation_rejects_an_incomplete_or_inverted_blood_pressure(): void
    {
        $anesthetist = $this->anesthetist();
        $request = $this->surgicalRequest($this->surgeon());

        $this->actingAs($anesthetist)
            ->post("/surgery/{$request->uuid}/anesthesia", [
                'consultation_data' => ['blood_pressure_systolic' => 120],
            ])
            ->assertSessionHasErrors('consultation_data.blood_pressure_systolic');

        $this->actingAs($anesthetist)
            ->post("/surgery/{$request->uuid}/anesthesia", [
                'consultation_data' => [
                    'blood_pressure_systolic' => 80,
                    'blood_pressure_diastolic' => 120,
                ],
            ])
            ->assertSessionHasErrors('consultation_data.blood_pressure_diastolic');

        $this->assertDatabaseCount('anesthesia_records', 0);
    }

    public function test_anesthetist_assignment_rejects_a_non_anesthetist_account(): void
    {
        $anesthetist = $this->anesthetist();
        $surgeon = $this->surgeon();
        $request = $this->surgicalRequest($surgeon);

        $this->actingAs($anesthetist)
            ->post("/surgery/{$request->uuid}/anesthesia", [
                'anesthetist_id' => $surgeon->id,
                'consultation_data' => ['admission_reason' => 'Préparation'],
            ])
            ->assertSessionHasErrors('anesthetist_id');

        $this->assertDatabaseCount('anesthesia_records', 0);
    }

    public function test_anesthesia_and_surgery_workspaces_are_independently_authorized(): void
    {
        $surgeon = $this->surgeon();
        $anesthetist = $this->anesthetist();
        $request = $this->surgicalRequest($surgeon);

        $this->actingAs($anesthetist)->get('/anesthesia')->assertOk();
        $this->actingAs($anesthetist)->get('/surgery')->assertForbidden();
        $this->actingAs($anesthetist)->get("/anesthesia/{$request->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surgery/Show')
                ->where('workspace', 'anesthesia')
                ->missing('surgicalRequest.report')
                ->missing('surgicalRequest.consumables')
                ->missing('surgicalRequest.preparation_notes'));

        $anesthesiaView = Permission::query()->where('name', 'anesthesia.view')->firstOrFail();
        $this->actingAs($surgeon)->get('/surgery')->assertOk();
        $this->actingAs($surgeon)->get('/anesthesia')->assertForbidden();

        $surgeon->permissions()->attach($anesthesiaView, ['effect' => 'allow']);
        $surgeon = $surgeon->fresh();
        $this->actingAs($surgeon)->get('/anesthesia')->assertOk();
        $this->actingAs($surgeon)->get("/surgery/{$request->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surgery/Show')
                ->has('surgicalRequest.anesthesia_record'));

        $surgeon->permissions()->updateExistingPivot($anesthesiaView->id, ['effect' => 'deny']);
        $surgeon = $surgeon->fresh();

        $this->actingAs($surgeon)->get('/anesthesia')->assertForbidden();
        $this->actingAs($surgeon)->get("/surgery/{$request->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surgery/Show')
                ->where('workspace', 'surgery')
                ->missing('surgicalRequest.anesthesia_record'));
    }

    public function test_both_workspaces_reuse_the_permission_filtered_care_record_in_read_only_mode(): void
    {
        $surgeon = $this->surgeon();
        $anesthetist = $this->anesthetist();
        $request = $this->surgicalRequest($surgeon);

        CareRecord::query()->create([
            'episode_id' => $request->episode_id,
            'blood_group' => 'O+',
            'blood_pressure_systolic' => 120,
            'blood_pressure_diastolic' => 80,
            'heart_rate' => 72,
            'spo2' => 98,
            'temperature_celsius' => 36.8,
            'allergy_note' => 'Pénicilline signalée',
            'allergy_snapshot' => [['substance' => 'Pénicilline']],
            'diagnostic_note' => 'Surveillance préopératoire',
            'transmission_reason' => 'Vigilance allergique',
            'created_by' => $anesthetist->id,
            'updated_by' => $anesthetist->id,
        ]);

        $careProjection = fn (Assert $page) => $page
            ->where('careSummary.read_only', true)
            ->where('careSummary.blood_group', 'O+')
            ->where('careSummary.blood_pressure_systolic', 120)
            ->where('careSummary.heart_rate', 72)
            ->where('careSummary.spo2', 98)
            ->where('careSummary.allergy_note', 'Pénicilline signalée')
            ->where('careSummary.transmission_reason', 'Vigilance allergique')
            ->missing('surgicalRequest.episode.care_record');

        $this->actingAs($surgeon)
            ->get("/surgery/{$request->uuid}")
            ->assertOk()
            ->assertInertia($careProjection);

        $this->actingAs($anesthetist)
            ->get("/anesthesia/{$request->uuid}")
            ->assertOk()
            ->assertInertia($careProjection);

        $careView = Permission::query()->where('name', 'care.view')->firstOrFail();
        $anesthetist->permissions()->syncWithoutDetaching([
            $careView->id => ['effect' => 'deny'],
        ]);

        $this->actingAs($anesthetist->fresh())
            ->get("/anesthesia/{$request->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('careSummary', null)
                ->missing('surgicalRequest.episode.care_record'));
    }

    public function test_anesthetic_items_are_controlled_snapshotted_and_validated(): void
    {
        $anesthetist = $this->anesthetist();
        $request = $this->surgicalRequest($this->surgeon());

        $this->actingAs($anesthetist)
            ->post("/surgery/{$request->uuid}/anesthesia", [
                'anesthetic_items' => [
                    [
                        'reference_code' => 'ANESTH-KETAMINE',
                        'details' => 'Dilution confirmée',
                        'quantity' => 2,
                        'unit' => 'ml',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $record = $request->fresh()->anesthesiaRecord;
        $this->assertSame('Kétamine', $record->anesthetic_items[0]['label_snapshot']);
        $this->assertSame('MEDICATION', $record->anesthetic_items[0]['category']);
        $this->assertSame('ml', $record->anesthetic_items[0]['unit']);

        $this->actingAs($anesthetist)
            ->put("/surgery/{$request->uuid}/anesthesia/{$record->id}", [
                'anesthetic_items' => [
                    ['reference_code' => 'ANESTH-UNKNOWN'],
                ],
            ])
            ->assertSessionHasErrors('anesthetic_items.0.reference_code');

        $this->actingAs($anesthetist)
            ->put("/surgery/{$request->uuid}/anesthesia/{$record->id}", [
                'anesthetic_items' => [
                    ['reference_code' => 'ANESTH-OTHER', 'details' => ''],
                ],
            ])
            ->assertSessionHasErrors('anesthetic_items.0.details');

        $this->assertSame('ANESTH-KETAMINE', $record->fresh()->anesthetic_items[0]['reference_code']);
    }
}
