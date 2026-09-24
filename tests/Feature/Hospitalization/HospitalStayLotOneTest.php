<?php

namespace Tests\Feature\Hospitalization;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalCareLevel;
use App\Enums\HospitalStayEndReason;
use App\Enums\HospitalStayStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\MedicalReferral;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VitalSignReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-161 — lot 1 du séjour hospitalier : les défauts corrigés.
 */
class HospitalStayLotOneTest extends TestCase
{
    use RefreshDatabase;

    // ── Transfert externe : le séjour se termine au départ ─────────────────

    public function test_the_departure_ends_the_stay_and_frees_the_passage(): void
    {
        $doctor = $this->doctor();
        [$episode, $stay, $arrival] = $this->admitted($doctor);
        $this->completeArrival($doctor, $arrival);

        // ADR-162 — le transfert se demande depuis le séjour.
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/transfert", [
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();
        $referral = MedicalReferral::query()->where('episode_id', $episode->id)->sole();

        // La décision ne sort pas le patient de son lit : l'ambulance n'est pas partie.
        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);

        $this->actingAs($doctor)->post("/transferts/{$referral->uuid}/depart", [
            'facility' => 'CHU Mahajanga',
            'departed_at' => now()->subMinute()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        $stay->refresh();
        $this->assertSame(HospitalStayStatus::Discharged, $stay->status);
        $this->assertSame(HospitalStayEndReason::Transfer, $stay->end_reason);
        $this->assertSame($referral->id, $stay->medical_referral_id);
        $this->assertNull($stay->active_key);
        $this->assertSame(EpisodeOrientationStatus::Completed, $stay->episodeOrientation->fresh()->status);
        $this->assertNull($stay->currentMovement()->first(), 'le lit ne reste pas occupé');
        $this->assertSame(EpisodeMedicalStatus::Transferred, $episode->fresh()->medical_status);
    }

    public function test_a_hospitalized_patient_cannot_be_discharged_as_transfer_by_the_other_path(): void
    {
        $doctor = $this->doctor();
        [, $stay, $arrival] = $this->admitted($doctor);
        $this->completeArrival($doctor, $arrival);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/sortie", [
            'type' => 'TRANSFER',
            'transfer_destination' => 'CHU Mahajanga',
            'discharged_at' => now()->format('Y-m-d H:i'),
            'final_diagnosis' => 'Pancréatite',
            'patient_condition' => 'Stable',
        ])->assertSessionHasErrors('type');

        $this->assertSame(HospitalStayStatus::Active, $stay->fresh()->status);

        // L'écran ne le propose pas non plus.
        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where(
                'orderOptions.discharge_types',
                fn ($types) => ! collect($types)->pluck('value')->contains('TRANSFER'),
            ));
    }

    public function test_a_home_discharge_records_why_the_stay_ended(): void
    {
        $doctor = $this->doctor();
        [, $stay, $arrival] = $this->admitted($doctor);
        $this->completeArrival($doctor, $arrival);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/sortie", [
            'type' => 'NORMAL',
            'discharged_at' => now()->format('Y-m-d H:i'),
            'final_diagnosis' => 'Gastro-entérite guérie',
            'patient_condition' => 'Guéri',
        ])->assertSessionHasNoErrors();

        $stay->refresh();
        $this->assertSame(HospitalStayStatus::Discharged, $stay->status);
        $this->assertSame(HospitalStayEndReason::Home, $stay->end_reason);
        $this->assertNull($stay->currentMovement()->first());
    }

    // ── Emplacement : un historique, plus rien ne s'écrase ──────────────────

    public function test_admission_opens_the_first_placement(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);

        $movement = $stay->movements()->sole();
        $this->assertSame('Médecine interne', $movement->service);
        $this->assertSame(HospitalCareLevel::Standard, $movement->care_level);
        $this->assertNull($movement->ended_at);
        $this->assertSame($doctor->id, $movement->moved_by);
    }

    public function test_an_aggravation_moves_the_patient_to_intensive_care_and_keeps_the_history(): void
    {
        $doctor = $this->doctor();
        [$episode, $stay] = $this->admitted($doctor);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/mouvements", [
            'service' => 'Réanimation',
            'room_bed' => 'Box 2',
            'care_level' => 'INTENSIVE',
            'reason' => 'Choc septique',
        ])->assertSessionHasNoErrors();

        $movements = $stay->movements()->get();
        $this->assertCount(2, $movements);
        $this->assertNotNull($movements[0]->ended_at, 'le premier emplacement est fermé, pas écrasé');
        $this->assertSame('Médecine interne', $movements[0]->service);
        $this->assertSame(HospitalCareLevel::Intensive, $movements[1]->care_level);
        $this->assertSame('Choc septique', $movements[1]->reason);

        // Le séjour continue : une mutation n'est pas une sortie.
        $stay->refresh();
        $this->assertSame(HospitalStayStatus::Active, $stay->status);
        $this->assertSame('Réanimation', $stay->service);
        $this->assertSame('Box 2', $stay->room_bed);
        $this->assertSame(EpisodeMedicalStatus::Hospitalized, $episode->fresh()->medical_status);
    }

    public function test_a_move_that_changes_nothing_is_refused(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/mouvements", [
            'service' => 'Médecine interne',
            'care_level' => 'STANDARD',
        ])->assertSessionHasErrors('service');

        $this->assertSame(1, $stay->movements()->count());
    }

    public function test_correcting_the_current_bed_is_not_a_move(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);

        $this->actingAs($doctor)->put("/hospitalisation/{$stay->uuid}", [
            'service' => 'Médecine interne',
            'room_bed' => 'Ch. 3 — lit B',
        ])->assertSessionHasNoErrors();

        $movement = $stay->movements()->sole();
        $this->assertSame('Ch. 3 — lit B', $movement->room_bed);
        $this->assertSame($doctor->id, $movement->updated_by);
    }

    public function test_moving_needs_the_right_to_update_the_stay_not_a_role(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $reader = $this->userWith('RECEPTION', ['hospitalization.view', 'patients.view']);

        $this->actingAs($reader)->post("/hospitalisation/{$stay->uuid}/mouvements", [
            'service' => 'Chirurgie',
            'care_level' => 'STANDARD',
        ])->assertForbidden();
    }

    // ── Surveillance répétée ────────────────────────────────────────────────

    public function test_vitals_are_recorded_again_and_again_during_the_stay(): void
    {
        $doctor = $this->doctor();
        [$episode, $stay] = $this->admitted($doctor);

        foreach ([['temperature_celsius' => '39.4', 'heart_rate' => 118], ['temperature_celsius' => '37.2', 'heart_rate' => 88]] as $values) {
            $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/surveillance", $values)
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, VitalSignReading::query()->where('hospital_stay_id', $stay->id)->count());
        $this->assertSame(2, VitalSignReading::query()->where('episode_id', $episode->id)->count());

        // Les repères existants classent chaque relevé (ADR-040, ADR-125).
        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('vitalReadings', 2)
                ->where('vitalReadings.1.alerts', fn ($alerts) => collect($alerts)->pluck('label')->contains('Fièvre')));
    }

    /**
     * ADR-161 — l'écran ne laisse jamais un cadre vide sans un mot : il dit qui
     * relève et quel droit manque (même règle qu'ADR-154 et ADR-158).
     */
    public function test_a_reader_without_the_right_is_told_who_records_and_what_is_missing(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $reader = $this->userWith('MEDECIN_LECTEUR', ['hospitalization.view', 'patients.view', 'vitals.view']);

        $this->actingAs($reader)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('capabilities.can_view_vitals', true)
                ->where('capabilities.can_record_vitals', false)
                ->where('capabilities.vitals_record_block', fn ($message) => str_contains((string) $message, 'vitals.create')));

        // Celui qui relève ne lit aucune explication : il a le formulaire.
        $this->actingAs($doctor)->get("/hospitalisation/{$stay->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('capabilities.vitals_record_block', null));
    }

    public function test_a_reading_uses_the_same_bounds_as_the_care_sheet(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/surveillance", [
            'blood_pressure_systolic' => 80,
            'blood_pressure_diastolic' => 120,
        ])->assertSessionHasErrors('blood_pressure_systolic');

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/surveillance", [])
            ->assertSessionHasErrors('blood_pressure_systolic');

        $this->assertDatabaseCount('vital_sign_readings', 0);
    }

    public function test_a_wrong_reading_is_corrected_and_its_author_kept(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/surveillance", ['temperature_celsius' => '32']);
        $reading = VitalSignReading::query()->sole();
        $colleague = $this->doctor();

        $this->actingAs($colleague)->put("/hospitalisation/{$stay->uuid}/surveillance/{$reading->uuid}", [
            'temperature_celsius' => '36.2',
        ])->assertSessionHasNoErrors();

        $reading->refresh();
        $this->assertSame('36.20', $reading->temperature_celsius);
        $this->assertSame($doctor->id, $reading->measured_by);
        $this->assertSame($colleague->id, $reading->updated_by);
        $this->assertDatabaseHas('audit_logs', ['entity_uuid' => $reading->uuid, 'action' => 'update']);
    }

    public function test_a_finished_stay_takes_no_more_readings(): void
    {
        $doctor = $this->doctor();
        [, $stay] = $this->admitted($doctor);
        $stay->update(['status' => HospitalStayStatus::Discharged, 'active_key' => null]);

        $this->actingAs($doctor)->post("/hospitalisation/{$stay->uuid}/surveillance", ['heart_rate' => 80])
            ->assertSessionHasErrors('measured_at');
    }

    // ── Aides ───────────────────────────────────────────────────────────────

    /** @return array{0: Episode, 1: HospitalStay, 2: EpisodeOrientation} */
    private function admitted(User $doctor): array
    {
        [$episode, $orientation] = $this->consultation($doctor);

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/hospitalization-requests", [
            'reason' => 'Déshydratation sévère',
            'admission_diagnosis' => 'Gastro-entérite aiguë',
            'requested_service' => 'Médecine interne',
            'priority' => 'URGENT',
        ])->assertSessionHasNoErrors();

        return [$episode->fresh(), HospitalStay::query()->where('episode_id', $episode->id)->sole(), $orientation];
    }

    /** La consultation d'arrivée est close : la visite sera une vraie rencontre du séjour. */
    private function completeArrival(User $doctor, EpisodeOrientation $orientation): void
    {
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
            $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/steps", $payload);
        }

        $this->actingAs($doctor)->post("/medicine/orientations/{$orientation->uuid}/complete")->assertSessionHasNoErrors();
    }

    private function doctor(): User
    {
        return $this->userWith('MEDICINE', [
            'medical_record.view', 'consultations.view', 'consultations.create', 'consultations.update',
            'patients.view', 'diagnoses.view', 'diagnoses.create', 'medical_discharge.create',
            'hospitalization.request', 'hospitalization.view', 'hospitalization.update',
            'transfer.request', 'transfers.view', 'transfers.manage',
            'vitals.view', 'vitals.create', 'vitals.update',
        ]);
    }

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
        // ADR-177 — une prestation d'arrivée n'ouvre plus de file : l'orientation
        // vers ce service est désormais un geste réel, posé ici explicitement.
        $this->app->make(CreateEpisodeOrientationAction::class)->execute($episode, CatalogModule::Reception, CatalogModule::Medicine, $doctor);
        $medicine = $episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->sole();
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($medicine, $doctor);

        return [$episode->fresh(), $medicine->fresh()];
    }
}
