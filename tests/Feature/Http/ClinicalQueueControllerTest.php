<?php

namespace Tests\Feature\Http;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
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
use App\Models\Episode;
use App\Models\EpisodeOrientation;
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

    /**
     * ADR-177 — le besoin ne décide plus qui voit le passage : un passage accueilli
     * est vu par les Soins **et** par la Médecine, quel que soit le parcours de sa
     * désignation. La vraie transmission Soins → Médecine reste, elle, une vraie
     * orientation, qui arrive chez le médecin comme une demande à prendre.
     */
    public function test_a_received_passage_is_seen_by_care_and_medicine_and_the_real_handoff_still_reaches_the_doctor(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.create', 'care.update', 'care.complete']);
        $doctor = $this->user('MEDICINE', ['consultations.view', 'consultations.create']);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $service = $this->service($nurse, ReceptionRoutingMode::CareThenMedicine);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
        ]], $nurse);

        $this->assertSame(0, EpisodeOrientation::query()->count());

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->component('Care/Index')
                ->has('passages.data', 1)
                ->where('passages.data.0.episode.uuid', $episode->uuid)
                ->where('passages.data.0.module.state', 'NONE')
                ->where('passages.data.0.actions.take_charge_url', route('care.passages.take-charge', $episode)));

        // Le même passage, vu du médecin, alors que la désignation passe d'abord par les Soins.
        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->component('Medicine/Index')
                ->has('passages.data', 1)
                ->where('passages.data.0.episode.uuid', $episode->uuid)
                ->where('passages.data.0.module.state', 'NONE'));

        // Regarder n'a rien créé.
        $this->assertSame(0, EpisodeOrientation::query()->count());

        $this->actingAs($nurse)->post(route('care.passages.take-charge', $episode))->assertRedirect();
        $care = $episode->orientations()->where('destination_module', CatalogModule::Care->value)->sole();
        $this->actingAs($nurse)->post("/care/orientations/{$care->uuid}/complete")->assertRedirect();

        $this->actingAs($doctor)->get('/medicine?view=waiting')
            ->assertInertia(fn ($page) => $page
                ->has('passages.data', 1)
                ->where('passages.data.0.episode.uuid', $episode->uuid)
                ->where('passages.data.0.module.state', 'REQUESTED')
                ->where('passages.data.0.module.source_label', CatalogModule::Care->label())
                ->where('passages.data.0.module.queue_number', 1));
    }

    /** L'exception de l'urgence (ADR-021, ADR-056) : deux vraies orientations d'emblée, rien de changé. */
    public function test_emergency_patient_is_visible_in_both_queues_immediately(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.create']);
        $doctor = $this->user('MEDICINE', ['consultations.view']);
        $episode = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient(), EpisodePriority::Emergency);

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page
                ->has('passages.data', 1)
                ->where('passages.data.0.episode.uuid', $episode->uuid)
                ->where('passages.data.0.module.state', 'REQUESTED'));
        $this->actingAs($doctor)->get('/medicine?view=emergency')
            ->assertInertia(fn ($page) => $page
                ->has('passages.data', 1)
                ->where('view', 'emergency')
                ->where('counts.emergency', 1)
                ->where('passages.data.0.episode.uuid', $episode->uuid)
                ->where('passages.data.0.module.state', 'REQUESTED'));
    }

    public function test_care_board_supports_the_emergency_view(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.create']);
        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000001'), EpisodePriority::Emergency);
        $normal = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000002'), EpisodePriority::Normal);
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($normal, [[
            'catalog_item_uuid' => $this->service($nurse, ReceptionRoutingMode::CareOnly)->uuid,
            'quantity' => 1,
        ]], $nurse);

        $this->actingAs($nurse)->get('/care?view=emergency')
            ->assertInertia(fn ($page) => $page
                ->has('passages.data', 1)
                ->where('passages.data.0.episode.uuid', $emergency->uuid)
                ->where('counts.waiting', 2)
                ->where('counts.emergency', 1));

        $this->actingAs($nurse)->get('/care')
            ->assertInertia(fn ($page) => $page->has('passages.data', 2));
    }

    /**
     * ADR-177 — tout patient en attente a son n° de file, par ordre d'arrivée à
     * la clinique, qu'une vraie orientation l'ait envoyé ici ou non. Une urgence
     * pas encore vue passe en tête, sans numéro : elle ne prend la place de personne.
     */
    public function test_care_queue_numbers_every_waiting_patient_by_arrival_and_skips_fresh_emergencies(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.create']);
        $doctor = $this->user('MEDICINE', ['consultations.view']);

        $normalFirst = $this->plannedEpisode('M-000001', $nurse, ReceptionRoutingMode::CareOnly);
        $normalSecond = $this->plannedEpisode('M-000002', $nurse, ReceptionRoutingMode::CareOnly);
        $free = $this->plannedEpisode('M-000004', $nurse, ReceptionRoutingMode::CareOnly);
        $this->askCare($normalFirst, $doctor);
        $this->askCare($normalSecond, $doctor);

        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000003'), EpisodePriority::Emergency);

        // Deterministic arrival order regardless of clock resolution.
        foreach ([[$normalFirst, 40], [$normalSecond, 30], [$free, 20], [$emergency, 10]] as [$episode, $minutes]) {
            $episode->forceFill(['started_at' => now()->subMinutes($minutes)])->saveQuietly();
        }

        $this->actingAs($nurse)->get('/care?view=waiting')
            ->assertInertia(fn ($page) => $page
                ->has('passages.data', 4)
                // Emergency stays pinned first regardless of arrival time,
                // but carries no queue number yet — Médecine hasn't seen it.
                ->where('passages.data.0.episode.uuid', $emergency->uuid)
                ->where('passages.data.0.module.queue_number', null)
                ->where('passages.data.1.episode.uuid', $normalFirst->uuid)
                ->where('passages.data.1.module.queue_number', 1)
                ->where('passages.data.2.episode.uuid', $normalSecond->uuid)
                ->where('passages.data.2.module.queue_number', 2)
                // Le passage sans orientation est dans la même file, avec son numéro.
                ->where('passages.data.3.episode.uuid', $free->uuid)
                ->where('passages.data.3.module.state', 'NONE')
                ->where('passages.data.3.module.queue_number', 3));
    }

    public function test_an_emergency_episode_joins_the_numbered_queue_once_medecine_completes_its_first_consultation(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.create']);
        $doctor = $this->user('MEDICINE', [
            'consultations.view', 'consultations.create', 'diagnoses.create', 'medical_discharge.create',
        ]);

        $normal = $this->plannedEpisode('M-000001', $nurse, ReceptionRoutingMode::CareOnly);
        $normalCare = $this->askCare($normal, $doctor);
        $normalCare->forceFill(['oriented_at' => now()->subMinutes(30)])->saveQuietly();

        // Arrived after the normal patient — while still an unseen
        // Emergency it must nonetheless show first (fast-tracked).
        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000002'), EpisodePriority::Emergency);
        $emergencyCare = $emergency->orientations()->where('destination_module', 'CARE')->sole();
        $emergencyMedicine = $emergency->orientations()->where('destination_module', 'MEDICINE')->sole();
        $emergencyCare->forceFill(['oriented_at' => now()->subMinutes(10)])->saveQuietly();

        $this->actingAs($nurse)->get('/care?view=waiting')
            ->assertInertia(fn ($page) => $page
                ->where('passages.data.0.module.orientation_uuid', $emergencyCare->uuid)
                ->where('passages.data.0.module.queue_number', null)
                ->where('passages.data.1.module.orientation_uuid', $normalCare->uuid));

        $this->app->make(AcceptMedicineOrientationAction::class)->execute($emergencyMedicine, $doctor);
        $this->app->make(RecordMedicalDischargeAction::class)->execute($emergencyMedicine, [
            'type' => MedicalDischargeType::Normal->value,
            'final_diagnosis' => 'Diagnostic de test',
            'patient_condition' => 'Stable',
            'discharged_at' => now(),
        ], $doctor);
        // Since ADR-084 the Médecine orientation is completed by closing the
        // consultation, not by recording the discharge.
        $emergencyMedicine->fresh()->complete($doctor);

        // Médecine has now seen this patient once: the still-open Care
        // orientation is no longer fast-tracked, joins the numbered queue,
        // and — having arrived after the normal patient — takes its real
        // place behind them rather than staying pinned first.
        $this->actingAs($nurse)->get('/care?view=waiting')
            ->assertInertia(fn ($page) => $page
                ->where('passages.data.0.module.orientation_uuid', $normalCare->uuid)
                ->where('passages.data.0.module.queue_number', 1)
                ->where('passages.data.1.module.orientation_uuid', $emergencyCare->uuid)
                ->where('passages.data.1.module.queue_number', 2));
    }

    public function test_medicine_queue_numbers_real_orientations_by_arrival_and_skips_fresh_emergencies(): void
    {
        $doctor = $this->user('MEDICINE', ['consultations.view', 'consultations.create']);

        $normalFirst = $this->plannedEpisode('M-000001', $doctor, ReceptionRoutingMode::MedicineDirect);
        $this->askMedicine($normalFirst, $doctor);

        $emergency = $this->app->make(CreateEpisodeAction::class)
            ->execute($this->patient('M-000002'), EpisodePriority::Emergency);

        $normalFirst->orientations()->where('destination_module', 'MEDICINE')->sole()
            ->forceFill(['oriented_at' => now()->subMinutes(20)])->saveQuietly();
        $emergency->orientations()->where('destination_module', 'MEDICINE')->sole()
            ->forceFill(['oriented_at' => now()->subMinutes(10)])->saveQuietly();

        $this->actingAs($doctor)->get('/medicine?view=waiting')
            ->assertInertia(fn ($page) => $page
                ->has('passages.data', 2)
                ->where('passages.data.0.episode.uuid', $emergency->uuid)
                ->where('passages.data.0.module.queue_number', null)
                ->where('passages.data.1.episode.uuid', $normalFirst->uuid)
                ->where('passages.data.1.module.queue_number', 1));
    }

    public function test_a_patient_already_taken_in_charge_gives_up_their_queue_number(): void
    {
        $doctor = $this->user('MEDICINE', ['consultations.view', 'consultations.create']);

        $seen = $this->plannedEpisode('M-000011', $doctor, ReceptionRoutingMode::MedicineDirect);
        $waiting = $this->plannedEpisode('M-000012', $doctor, ReceptionRoutingMode::MedicineDirect);
        $seen->forceFill(['started_at' => now()->subMinutes(30)])->saveQuietly();
        $waiting->forceFill(['started_at' => now()->subMinutes(5)])->saveQuietly();
        $seenOrientation = $this->askMedicine($seen, $doctor);

        // Arrived first, but already in consultation: their place in the
        // waiting line is used up, and the next person who waits is N°1.
        $this->app->make(AcceptMedicineOrientationAction::class)->execute($seenOrientation, $doctor);

        $this->actingAs($doctor)->get('/medicine')
            ->assertInertia(fn ($page) => $page
                ->where('view', 'waiting')
                ->has('passages.data', 1)
                ->where('passages.data.0.episode.uuid', $waiting->uuid)
                ->where('passages.data.0.module.queue_number', 1));

        $this->actingAs($doctor)->get('/medicine?view=in_progress')
            ->assertInertia(fn ($page) => $page
                ->where('passages.data.0.episode.uuid', $seen->uuid)
                ->where('passages.data.0.status', 'IN_PROGRESS')
                ->where('passages.data.0.module.queue_number', null));
    }

    /** Le besoin se lit sur la ligne, même sans tarif — et jamais avec un montant (ADR-036). */
    public function test_board_keeps_a_mutual_designation_visible_when_its_tariff_is_not_configured(): void
    {
        $nurse = $this->user('NURSE', ['care.view', 'care.create']);
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

        $response = $this->actingAs($nurse)->get('/care')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Care/Index')
                ->has('passages.data', 1)
                ->where('passages.data.0.needs.0.description', 'Consultation'));

        $row = $response->viewData('page')['props']['passages']['data'][0];
        $this->assertArrayNotHasKey('total_amount', $row['needs'][0]);
        $this->assertArrayNotHasKey('unit_price', $row['needs'][0]);
    }

    public function test_queue_routes_enforce_their_own_permissions(): void
    {
        $unauthorized = $this->user('PHARMACY', []);
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient());
        $this->app->make(PlanEpisodeRoutingAction::class)->planUnknownNeed($episode, $unauthorized);
        $this->assertSame(0, $episode->orientations()->count());
        $care = $this->app->make(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Reception, CatalogModule::Care, $unauthorized);

        $this->actingAs($unauthorized)->get('/care')->assertForbidden();
        $this->actingAs($unauthorized)->get('/medicine')->assertForbidden();
        $this->actingAs($unauthorized)->post("/care/orientations/{$care->uuid}/accept")->assertForbidden();
        $this->actingAs($unauthorized)->post(route('care.passages.take-charge', $episode))->assertForbidden();
        $this->actingAs($unauthorized)->post(route('medicine.passages.take-charge', $episode))->assertForbidden();
    }

    private function plannedEpisode(string $number, User $actor, ReceptionRoutingMode $route): Episode
    {
        $episode = $this->app->make(CreateEpisodeAction::class)->execute($this->patient($number));
        $this->app->make(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $this->service($actor, $route)->uuid,
            'quantity' => 1,
        ]], $actor);

        return $episode->fresh();
    }

    /** Une vraie orientation vers les Soins — celle d'un médecin qui demande un soin, par exemple. */
    private function askCare(Episode $episode, User $actor): EpisodeOrientation
    {
        return $this->app->make(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Medicine, CatalogModule::Care, $actor, 'Soin demandé par le médecin.');
    }

    /** Une vraie orientation vers la Médecine — la transmission des Soins, par exemple. */
    private function askMedicine(Episode $episode, User $actor): EpisodeOrientation
    {
        return $this->app->make(CreateEpisodeOrientationAction::class)
            ->execute($episode, CatalogModule::Care, CatalogModule::Medicine, $actor, 'Transmission des Soins.');
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
