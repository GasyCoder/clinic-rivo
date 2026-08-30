<?php

namespace Tests\Feature\Http;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\RecordMedicalDischargeAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodePriority;
use App\Enums\MedicalDischargeType;
use App\Enums\PatientType;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\PatientMutualCoverage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalQueueControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => $roleCode]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patient(string $number = 'M-000001'): Patient
    {
        return Patient::create([
            'patient_number' => $number,
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    public function test_normal_patient_is_hidden_from_medicine_until_care_completes_the_handoff(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.update', 'care.complete']);
        $doctor = $this->user('MEDICINE', ['consultations.view', 'consultations.create']);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $service = $this->service($nurse, ReceptionRoutingMode::CareThenMedicine);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
        ]], $nurse);
        $care = $episode->orientations()
            ->where('destination_module', CatalogModule::Care->value)
            ->sole();

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->component('Care/Index')
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid));

        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Index')
                ->has('orientations.data', 0));

        $this->actingAs($nurse)
            ->post("/care/orientations/{$care->uuid}/accept")
            ->assertRedirect();
        $this->actingAs($nurse)
            ->post("/care/orientations/{$care->uuid}/complete")
            ->assertRedirect();

        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Index')
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid)
                ->where('orientations.data.0.source_module', CatalogModule::Care->value));
    }

    public function test_emergency_patient_is_visible_in_both_queues_immediately(): void
    {
        $nurse = $this->user('NURSE', ['care.view']);
        $doctor = $this->user('MEDICINE', ['consultations.view']);
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient(), EpisodePriority::Emergency);

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid));
        $this->actingAs($doctor)->get('/medicine?filter=emergency')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 1)
                ->where('filter', 'emergency')
                ->where('counts.emergency', 1)
                ->where('orientations.data.0.episode.uuid', $episode->uuid));
    }

    public function test_care_queue_supports_the_priority_filter(): void
    {
        $nurse = $this->user('NURSE', ['care.view']);
        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000001'), EpisodePriority::Emergency);
        $normal = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000002'), EpisodePriority::Normal);
        $normalService = $this->service($nurse, ReceptionRoutingMode::CareOnly);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($normal, [[
            'catalog_item_uuid' => $normalService->uuid,
            'quantity' => 1,
        ]], $nurse);

        $this->actingAs($nurse)->get('/care?priority=emergency')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $emergency->uuid)
                ->where('priorityCounts.all', 2)
                ->where('priorityCounts.emergency', 1)
                ->where('priorityCounts.normal', 1));

        $this->actingAs($nurse)->get('/care?priority=normal')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.uuid', $normal->uuid));

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page->has('orientations.data', 2));
    }

    public function test_care_queue_numbers_normal_patients_by_arrival_and_skips_fresh_emergencies(): void
    {
        $nurse = $this->user('NURSE', ['care.view']);

        $normalFirst = $this->app->make(CreateEpisodeAction::class)->execute($this->patient('M-000001'));
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($normalFirst, [[
            'catalog_item_uuid' => $this->service($nurse, ReceptionRoutingMode::CareOnly)->uuid,
            'quantity' => 1,
        ]], $nurse);

        $normalSecond = $this->app->make(CreateEpisodeAction::class)->execute($this->patient('M-000002'));
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($normalSecond, [[
            'catalog_item_uuid' => $this->service($nurse, ReceptionRoutingMode::CareOnly)->uuid,
            'quantity' => 1,
        ]], $nurse);

        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000003'), EpisodePriority::Emergency);

        // Deterministic arrival order regardless of clock resolution.
        $normalFirst->orientations()->where('destination_module', 'CARE')->sole()
            ->forceFill(['oriented_at' => now()->subMinutes(30)])->saveQuietly();
        $normalSecond->orientations()->where('destination_module', 'CARE')->sole()
            ->forceFill(['oriented_at' => now()->subMinutes(20)])->saveQuietly();
        $emergency->orientations()->where('destination_module', 'CARE')->sole()
            ->forceFill(['oriented_at' => now()->subMinutes(10)])->saveQuietly();

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 3)
                // Emergency stays pinned first regardless of arrival time,
                // but carries no queue number yet — Médecine hasn't seen it.
                ->where('orientations.data.0.episode.uuid', $emergency->uuid)
                ->where('orientations.data.0.queue_number', null)
                ->where('orientations.data.1.episode.uuid', $normalFirst->uuid)
                ->where('orientations.data.1.queue_number', 1)
                ->where('orientations.data.2.episode.uuid', $normalSecond->uuid)
                ->where('orientations.data.2.queue_number', 2));
    }

    public function test_an_emergency_episode_joins_the_numbered_queue_once_medecine_completes_its_first_consultation(): void
    {
        $nurse = $this->user('NURSE', ['care.view']);
        $doctor = $this->user('MEDICINE', [
            'consultations.view', 'consultations.create', 'diagnoses.create', 'medical_discharge.create',
        ]);

        $normal = $this->app->make(CreateEpisodeAction::class)->execute($this->patient('M-000001'));
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($normal, [[
            'catalog_item_uuid' => $this->service($nurse, ReceptionRoutingMode::CareOnly)->uuid,
            'quantity' => 1,
        ]], $nurse);
        $normalCare = $normal->orientations()->where('destination_module', 'CARE')->sole();
        $normalCare->forceFill(['oriented_at' => now()->subMinutes(30)])->saveQuietly();

        // Arrived after the normal patient — while still an unseen
        // Emergency it must nonetheless show first (fast-tracked).
        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000002'), EpisodePriority::Emergency);
        $emergencyCare = $emergency->orientations()->where('destination_module', 'CARE')->sole();
        $emergencyMedicine = $emergency->orientations()->where('destination_module', 'MEDICINE')->sole();
        $emergencyCare->forceFill(['oriented_at' => now()->subMinutes(10)])->saveQuietly();

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->where('orientations.data.0.uuid', $emergencyCare->uuid)
                ->where('orientations.data.0.queue_number', null)
                ->where('orientations.data.1.uuid', $normalCare->uuid));

        $consultation = $this->app->make(AcceptMedicineOrientationAction::class)
            ->execute($emergencyMedicine, $doctor)
            ->consultation;
        $this->app->make(RecordMedicalDischargeAction::class)->execute($emergencyMedicine, [
            'type' => MedicalDischargeType::Normal->value,
            'final_diagnosis' => 'Diagnostic de test',
            'patient_condition' => 'Stable',
            'discharged_at' => now(),
        ], $doctor);

        // Médecine has now seen this patient once: the still-open Care
        // orientation is no longer fast-tracked, joins the numbered queue,
        // and — having arrived after the normal patient — takes its real
        // place behind them rather than staying pinned first.
        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->where('orientations.data.0.uuid', $normalCare->uuid)
                ->where('orientations.data.0.queue_number', 1)
                ->where('orientations.data.1.uuid', $emergencyCare->uuid)
                ->where('orientations.data.1.queue_number', 2));
    }

    public function test_medicine_queue_numbers_normal_patients_by_arrival_and_skips_fresh_emergencies(): void
    {
        $doctor = $this->user('MEDICINE', ['consultations.view', 'consultations.create']);

        $normalFirst = $this->app->make(CreateEpisodeAction::class)->execute($this->patient('M-000001'));
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($normalFirst, [[
            'catalog_item_uuid' => $this->service($doctor, ReceptionRoutingMode::MedicineDirect)->uuid,
            'quantity' => 1,
        ]], $doctor);

        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000002'), EpisodePriority::Emergency);

        $normalFirst->orientations()->where('destination_module', 'MEDICINE')->sole()
            ->forceFill(['oriented_at' => now()->subMinutes(20)])->saveQuietly();
        $emergency->orientations()->where('destination_module', 'MEDICINE')->sole()
            ->forceFill(['oriented_at' => now()->subMinutes(10)])->saveQuietly();

        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->has('orientations.data', 2)
                ->where('orientations.data.0.episode.uuid', $emergency->uuid)
                ->where('orientations.data.0.queue_number', null)
                ->where('orientations.data.1.episode.uuid', $normalFirst->uuid)
                ->where('orientations.data.1.queue_number', 1));
    }

    public function test_queue_keeps_a_mutual_designation_visible_when_its_tariff_is_not_configured(): void
    {
        $nurse = $this->user('NURSE', ['care.view']);
        $patient = $this->patient();
        $patient->update(['patient_type' => PatientType::Mutual]);
        $organization = MutualOrganization::query()->create([
            'name' => 'Mutuelle de test',
            'active' => true,
        ]);
        PatientMutualCoverage::query()->create([
            'patient_id' => $patient->id,
            'mutual_organization_id' => $organization->id,
            'employer_name' => 'Employeur de test',
            'beneficiary_type' => 'PRINCIPAL',
            'membership_number' => 'MUT-001',
            'effective_from' => now(),
            'created_by' => $nurse->id,
        ]);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $service = $this->service($nurse, ReceptionRoutingMode::CareOnly);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 2,
        ]], $nurse);

        $this->actingAs($nurse)->get('/care')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Care/Index')
                ->has('orientations.data', 1)
                ->where('orientations.data.0.episode.designations.0.description', 'Consultation')
                ->where('orientations.data.0.episode.designations.0.total_amount', null)
            );
    }

    public function test_queue_routes_enforce_their_own_permissions(): void
    {
        $unauthorized = $this->user('PHARMACY', []);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $this->app->make(PlanEpisodeRoutingAction::class)->planUnknownNeed($episode, $unauthorized);
        $care = $episode->orientations()->sole();

        $this->actingAs($unauthorized)->get('/care')->assertForbidden();
        $this->actingAs($unauthorized)->get('/medicine')->assertForbidden();
        $this->actingAs($unauthorized)->post("/care/orientations/{$care->uuid}/accept")->assertForbidden();
    }

    private function service(User $actor, ReceptionRoutingMode $route): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => fake()->unique()->bothify('SRV-###'),
            'name' => 'Consultation',
            'type' => CatalogItemType::Service,
            'module' => CatalogModule::Medicine,
            'unit' => 'consultation',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => $route,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'amount' => '10000.00',
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test',
            'created_by' => $actor->id,
        ]);

        return $item;
    }
}
