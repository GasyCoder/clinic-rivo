<?php

namespace Tests\Feature\Transfer;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\MedicalDischarge;
use App\Models\MedicalReferral;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-114 — Transferts, Pédiatrie, et les demandes transmises en un clic
 * vers une destination qui a son propre module.
 */
class TransferAndPediatricsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transfer_leaves_in_one_click_and_is_completed_in_the_transfers_module(): void
    {
        $doctor = $this->doctor();
        [$episode, $orientation] = $this->consultation($doctor);

        // Ni établissement ni motif : ils se complètent dans le module.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'priority' => 'URGENT',
            'diagnosis' => 'Fracture ouverte du tibia',
        ])->assertSessionHasNoErrors();

        $referral = MedicalReferral::query()->sole();
        $this->assertNull($referral->facility);
        $this->assertNull($referral->departed_at);

        $nurse = $this->userWith('NURSE', ['transfers.view', 'transfers.manage']);

        $this->actingAs($nurse)->get('/transferts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transfers/Index')
                ->where('counts.pending', 1)
                ->where('referrals.data.0.uuid', $referral->uuid));

        // Le départ exige un établissement.
        $this->actingAs($nurse)->post("/transferts/{$referral->uuid}/depart", [
            'departed_at' => now()->subMinute()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('facility');

        $this->actingAs($nurse)->put("/transferts/{$referral->uuid}", [
            'facility' => 'CHU Androva',
            'reason' => 'Prise en charge orthopédique',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        $this->assertSame('CHU Androva', $referral->fresh()->facility);
        // Jusqu'au départ, le patient reste en soins.
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $this->completeConsultation($orientation);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $this->actingAs($nurse)->post("/transferts/{$referral->uuid}/depart", [
            'departed_at' => now()->subMinute()->format('Y-m-d H:i'),
            'departure_notes' => 'Ambulance du CHU',
        ])->assertSessionHasNoErrors();

        $referral->refresh();
        $this->assertNotNull($referral->departed_at);
        $this->assertSame($nurse->id, $referral->departed_by);
        $this->assertSame(EpisodeOrientationStatus::Completed, $referral->episodeOrientation->status);

        $episode->refresh();
        $this->assertSame(EpisodeMedicalStatus::Transferred, $episode->medical_status);
        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->administrative_status);

        // Un départ constaté ne se rejoue ni ne se modifie.
        $this->actingAs($nurse)->post("/transferts/{$referral->uuid}/depart", [
            'departed_at' => now()->subMinute()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('departure');
        $this->actingAs($nurse)->put("/transferts/{$referral->uuid}", [
            'facility' => 'Autre',
            'priority' => 'NORMAL',
        ])->assertSessionHasErrors('referral');

        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.depart']);
    }

    public function test_a_transmitted_request_cannot_be_sent_twice(): void
    {
        $doctor = $this->doctor(['transfers.view']);
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();

        // Un second clic créait un transfert en double.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'priority' => 'NORMAL',
        ])->assertSessionHasErrors('orientation');

        $this->assertDatabaseCount('medical_referrals', 1);
        $referral = MedicalReferral::query()->sole();

        // L'écran conduit au module au lieu de proposer de retransmettre.
        $this->actingAs($doctor)->get("/medicine/orientations/{$orientation->uuid}/cloture")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('consultation_orientation.active.status', 'SUBMITTED')
                ->where('consultation_orientation.active.request.module_url', "/transferts/{$referral->uuid}")
                ->where('consultation_orientation.active.request.module_label', 'Transferts'));
    }

    public function test_the_transfer_request_is_written_in_sanitised_rich_text(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'priority' => 'NORMAL',
            'clinical_summary' => "NFS — en attente\nÉchographie — normale",
        ])->assertSessionHasNoErrors();
        $referral = MedicalReferral::query()->sole();
        $nurse = $this->userWith('NURSE', ['transfers.view', 'transfers.manage']);

        // Un texte repris du dossier garde ses lignes, sans ligne vide ajoutée.
        $this->actingAs($nurse)->get("/transferts/{$referral->uuid}")
            ->assertInertia(fn ($page) => $page
                ->where('referral.clinical_summary_html', 'NFS — en attente<br>Échographie — normale'));

        $this->actingAs($nurse)->put("/transferts/{$referral->uuid}", [
            'priority' => 'NORMAL',
            'reason' => '<p><strong>Prise en charge</strong> orthopédique</p><script>alert(1)</script>',
            // Un éditeur vidé renvoie des balises sans texte.
            'clinical_summary' => '<p><br></p>',
        ])->assertSessionHasNoErrors();

        $referral->refresh();
        $this->assertSame('<p><strong>Prise en charge</strong> orthopédique</p>', $referral->reason);
        $this->assertNull($referral->clinical_summary);

        // La longueur se compte sur le texte lu, jamais sur les balises.
        $this->actingAs($nurse)->put("/transferts/{$referral->uuid}", [
            'priority' => 'NORMAL',
            'reason' => '<p>'.str_repeat('a', 3001).'</p>',
        ])->assertSessionHasErrors('reason');

        // La liste montre le texte, jamais les balises.
        $this->actingAs($nurse)->get('/transferts')
            ->assertInertia(fn ($page) => $page->where('referrals.data.0.reason', 'Prise en charge orthopédique'));
    }

    public function test_reception_reads_the_transfers_but_a_role_without_the_right_cannot(): void
    {
        $doctor = $this->doctor();
        [, $orientation] = $this->consultation($doctor);
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/medical-referrals", [
            'priority' => 'NORMAL',
        ])->assertSessionHasNoErrors();
        $referral = MedicalReferral::query()->sole();

        $reception = $this->userWith('RECEPTION', ['transfers.view']);
        $this->actingAs($reception)->get("/transferts/{$referral->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transfers/Show')
                ->where('capabilities.can_manage', false));
        $this->actingAs($reception)->post("/transferts/{$referral->uuid}/depart", [
            'facility' => 'CHU',
            'departed_at' => now()->subMinute()->format('Y-m-d H:i'),
        ])->assertForbidden();

        $pharmacy = $this->userWith('PHARMACY', []);
        $this->actingAs($pharmacy)->get('/transferts')->assertForbidden();
    }

    public function test_the_transfer_permissions_reach_medicine_nursing_and_reception(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);

        foreach (['MEDICINE', 'NURSE', 'RECEPTION'] as $code) {
            $role = Role::query()->where('code', $code)->sole();
            $this->assertTrue($role->permissions()->where('name', 'transfers.view')->exists(), "{$code} voit les transferts");
            $this->assertTrue($role->permissions()->where('name', 'transfers.manage')->exists(), "{$code} constate un départ");
        }

        $medicine = Role::query()->where('code', 'MEDICINE')->sole();
        $this->assertTrue($medicine->permissions()->where('name', 'pediatrics.manage')->exists());
        $this->assertFalse(Role::query()->where('code', 'PHARMACY')->sole()->permissions()->where('name', 'transfers.view')->exists());
    }

    public function test_pediatrics_takes_charge_and_ends_with_a_medical_discharge(): void
    {
        $doctor = $this->doctor(['pediatrics.request', 'pediatrics.view', 'pediatrics.manage']);
        [$episode, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Bronchiolite',
        ])->assertSessionHasNoErrors();

        // Un clic : aucun motif saisi.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/referrals", [
            'destination' => 'PEDIATRICS',
        ])->assertSessionHasNoErrors();

        $pediatrics = $episode->orientations()->where('destination_module', CatalogModule::Pediatrics->value)->sole();
        $this->assertSame(EpisodeOrientationStatus::Pending, $pediatrics->status);

        $this->actingAs($doctor)->get('/pediatrie')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pediatrics/Index')
                ->where('counts.active', 1)
                ->where('orientations.data.0.uuid', $pediatrics->uuid));

        $this->actingAs($doctor)->get("/pediatrie/{$pediatrics->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orientation.diagnoses.0', 'Bronchiolite')
                ->where('capabilities.can_accept', true)
                ->where('capabilities.can_discharge', false));

        // Pas de sortie avant la prise en charge.
        $this->actingAs($doctor)->post("/pediatrie/{$pediatrics->uuid}/sortie", $this->discharge())
            ->assertForbidden();

        $this->actingAs($doctor)->post("/pediatrie/{$pediatrics->uuid}/prise-en-charge")->assertSessionHasNoErrors();
        $this->assertSame(EpisodeOrientationStatus::InProgress, $pediatrics->fresh()->status);

        $this->completeConsultation($orientation);
        $this->assertSame(EpisodeAdministrativeStatus::InCare, $episode->fresh()->administrative_status);

        $this->actingAs($doctor)->post("/pediatrie/{$pediatrics->uuid}/sortie", $this->discharge(['final_diagnosis' => '']))
            ->assertSessionHasErrors('final_diagnosis');
        $this->actingAs($doctor)->post("/pediatrie/{$pediatrics->uuid}/sortie", $this->discharge())
            ->assertSessionHasNoErrors();

        $discharge = MedicalDischarge::query()->sole();
        $this->assertSame($orientation->consultation()->value('id'), $discharge->consultation_id);
        $this->assertSame(EpisodeOrientationStatus::Completed, $pediatrics->fresh()->status);

        $episode->refresh();
        $this->assertSame(EpisodeMedicalStatus::MedicallyDischarged, $episode->medical_status);
        $this->assertSame(EpisodeAdministrativeStatus::PendingSettlement, $episode->administrative_status);
    }

    public function test_a_surgical_request_generates_its_diagnosis_from_the_consultation(): void
    {
        $doctor = $this->doctor(['surgery.request']);
        [, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/diagnoses", [
            'type' => 'FINAL',
            'description' => 'Appendicite aiguë',
        ])->assertSessionHasNoErrors();

        $item = CatalogItem::query()->create([
            'code' => 'APPEND-'.uniqid(),
            'name' => 'Appendicectomie',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Surgery,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'created_by' => $doctor->id,
            'updated_by' => $doctor->id,
        ]);

        // Seule l'intervention est choisie : le diagnostic est généré.
        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/surgical-referrals", [
            'catalog_item_uuid' => $item->uuid,
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('Diagnostic : Appendicite aiguë', (string) SurgicalRequest::query()->sole()->notes);
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
            ...$extra,
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
