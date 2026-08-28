<?php

namespace Tests\Feature\Http;

use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\PatientType;
use App\Enums\ReceptionPatientStep;
use App\Models\AuditLog;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $names): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function patientData(array $overrides = []): array
    {
        return [
            'patient_type' => PatientType::Standard->value,
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            ...$overrides,
        ];
    }

    public function test_reception_index_requires_the_reception_view_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie'])->id]);

        $this->actingAs($user)->get('/reception')->assertForbidden();
    }

    public function test_patient_reception_requires_the_episodes_create_permission(): void
    {
        $user = $this->userWithPermissions(['reception.view']);

        $this->actingAs($user)->get('/reception')->assertOk();
        $this->actingAs($user)->get('/reception/patients')->assertForbidden();
        $this->actingAs($user)->get('/reception/patients/type')->assertForbidden();
    }

    public function test_search_returns_matching_patients(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        Patient::create(['patient_number' => 'M-000001', ...$this->patientData(['last_name' => 'Rakoto'])]);
        Patient::create(['patient_number' => 'M-000002', ...$this->patientData(['first_name' => 'Marie', 'last_name' => 'Rasoa'])]);

        $this->actingAs($user)->get('/reception/patients?q=Rakoto')
            ->assertRedirect('/reception/patients/identite?q=Rakoto');

        $this->actingAs($user)->get('/reception/patients/identite?q=Rakoto')
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('step', ReceptionPatientStep::Identity->value)
                ->has('matches', 1)
                ->where('matches.0.last_name', 'Rakoto')
            );
    }

    public function test_each_patient_reception_step_has_a_stable_refreshable_url(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);

        foreach (ReceptionPatientStep::cases() as $step) {
            $this->actingAs($user)
                ->get("/reception/patients/{$step->value}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Reception/Create')
                    ->where('step', $step->value));
        }

        $this->actingAs($user)
            ->get('/reception/patients/etape-inconnue')
            ->assertNotFound();
    }

    public function test_create_lists_recent_episodes_with_their_patient(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);
        $episode = Episode::create([
            'patient_id' => $patient->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'administrative_status' => 'PENDING_ORIENTATION',
            'started_at' => now()->subDays(7),
        ]);

        $this->actingAs($user)->get('/reception/patients')
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('step', null)
                ->has('recentEpisodes', 1)
                ->where('recentEpisodes.0.id', $episode->id)
                ->where('recentEpisodes.0.priority', EpisodePriority::Normal->value)
                ->where('recentEpisodes.0.patient.last_name', 'Rakoto')
            );
    }

    public function test_create_uses_one_filter_for_priority_or_orientation(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        config(['rivo.site.code' => 'M']);
        $createEpisode = $this->app->make(CreateEpisodeAction::class);
        $pending = $createEpisode->execute($patient);
        $pending->update(['started_at' => now()->subMinutes(30)]);
        $oriented = $createEpisode->execute($patient);
        $oriented->update(['started_at' => now()->subMinutes(20)]);
        $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $oriented,
            CatalogModule::Reception,
            CatalogModule::Medicine,
            $user,
        );
        $oriented->update([
            'administrative_status' => EpisodeAdministrativeStatus::Oriented,
            'service_plan_finalized_at' => now(),
        ]);
        $orientedEmergency = $createEpisode->execute($patient, EpisodePriority::Emergency);
        $orientedEmergency->update(['started_at' => now()->subMinutes(10)]);

        $this->actingAs($user)->get('/reception/patients?filter=emergency')
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('recentEpisodeFilter', 'emergency')
                ->has('recentEpisodes', 1)
                ->where('recentEpisodes.0.uuid', $orientedEmergency->uuid)
                ->where('recentEpisodes.0.priority', EpisodePriority::Emergency->value)
            );

        $this->actingAs($user)->get('/reception/patients?filter=oriented')
            ->assertInertia(fn ($page) => $page
                ->where('recentEpisodeFilter', 'oriented')
                ->has('recentEpisodes', 2)
                ->where('recentEpisodes.0.uuid', $orientedEmergency->uuid)
                ->where('recentEpisodes.0.administrative_status', EpisodeAdministrativeStatus::Oriented->value)
                ->where('recentEpisodes.1.uuid', $oriented->uuid)
            );

        $this->actingAs($user)->get('/reception/patients?filter=pending')
            ->assertInertia(fn ($page) => $page
                ->where('recentEpisodeFilter', 'pending')
                ->has('recentEpisodes', 1)
                ->where('recentEpisodes.0.uuid', $pending->uuid)
            );
    }

    public function test_a_care_only_passage_finished_at_soins_counts_as_oriented(): void
    {
        // ADR-030: CARE_ONLY never reaches Médecine — its Care orientation
        // is created, then completed, with no Medicine orientation ever
        // created. It must still land in "Orientés", not disappear from
        // both tabs once Soins marks it done.
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);
        config(['rivo.site.code' => 'M']);

        $episode = $this->app->make(CreateEpisodeAction::class)->execute($patient);
        $orientation = $this->app->make(CreateEpisodeOrientationAction::class)->execute(
            $episode,
            CatalogModule::Reception,
            CatalogModule::Care,
            $user,
        );
        $orientation->update(['status' => EpisodeOrientationStatus::Completed]);
        $episode->update([
            'administrative_status' => EpisodeAdministrativeStatus::Oriented,
            'service_plan_finalized_at' => now(),
        ]);

        $this->actingAs($user)->get('/reception/patients?filter=oriented')
            ->assertInertia(fn ($page) => $page
                ->has('recentEpisodes', 1)
                ->where('recentEpisodes.0.uuid', $episode->uuid));

        $this->actingAs($user)->get('/reception/patients?filter=pending')
            ->assertInertia(fn ($page) => $page->has('recentEpisodes', 0));
    }

    public function test_store_with_an_existing_patient_uuid_only_creates_an_episode(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post('/reception/patients', ['patient_uuid' => $patient->uuid]);

        $episode = $patient->episodes()->sole();
        $response->assertRedirect("/reception/passages/{$episode->uuid}/prestations");
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, $patient->episodes()->count());
    }

    public function test_existing_patient_arrival_does_not_silently_mutate_the_permanent_record(): void
    {
        $user = $this->userWithPermissions(['episodes.create', 'patients.update']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData([
            'phone' => '0320000000',
        ])]);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post('/reception/patients', [
            'patient_uuid' => $patient->uuid,
            'update_patient' => true,
            'first_name' => 'Jeanne',
            'last_name' => 'Rakoto',
            'age' => 36,
            'sex' => 'F',
            'civility' => 'MRS',
            'phone' => '0341234567',
            'email' => 'jeanne@example.mg',
            'address' => 'Mampikony',
            'emergency_contact_name' => 'Marie Rakoto',
            'emergency_contact_phone' => '0331234567',
        ]);

        $episode = $patient->episodes()->sole();
        $response->assertRedirect("/reception/passages/{$episode->uuid}/prestations");

        $patient->refresh();
        $this->assertSame('Jean', $patient->first_name);
        $this->assertSame('0320000000', $patient->phone);
        $this->assertNull($patient->email);
        $this->assertFalse($patient->birth_date_is_approximate);
        $this->assertSame('1990-05-12', $patient->birth_date->toDateString());
        $this->assertSame(1, $patient->episodes()->count());
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'update')
            ->where('entity_type', $patient->getMorphClass())
            ->where('entity_id', $patient->id)
            ->count());

        // ADR-034: unlike the identity fields above, the emergency contact
        // legitimately belongs to this arrival's episode, not the patient.
        $this->assertSame('Marie Rakoto', $episode->fresh()->emergency_contact_name);
        $this->assertSame('0331234567', $episode->fresh()->emergency_contact_phone);
    }

    public function test_existing_patient_arrival_needs_only_the_episode_create_permission(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $response = $this->actingAs($user)->post('/reception/patients', [
            'patient_uuid' => $patient->uuid,
            'update_patient' => true,
            ...$this->patientData(['last_name' => 'Changed']),
        ]);

        $response->assertRedirect(route('reception.passages.services.show', Episode::query()->sole()));
        $this->assertSame('Rakoto', $patient->fresh()->last_name);
        $this->assertSame(1, Episode::count());
    }

    public function test_new_patient_arrival_requires_the_patient_create_permission(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);

        $this->actingAs($user)
            ->post('/reception/patients', $this->patientData())
            ->assertForbidden();

        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Episode::count());
    }

    public function test_store_with_new_patient_data_creates_both_patient_and_episode(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post('/reception/patients', $this->patientData());

        $patient = Patient::first();
        $episode = $patient->episodes()->sole();
        $response->assertRedirect("/reception/passages/{$episode->uuid}/prestations");
        $this->assertSame('Jean', $patient->first_name);
        $this->assertSame('M-'.now()->format('y').'-0001', $patient->patient_number);
        $this->assertSame("{$patient->patient_number}-01", $episode->episode_number);
        $this->assertSame(1, $patient->episodes()->count());
    }

    public function test_store_keeps_a_declared_age_without_creating_a_false_birth_date(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);
        config(['rivo.site.code' => 'M']);

        $data = $this->patientData(['age' => 36]);
        unset($data['birth_date']);

        $this->actingAs($user)->post('/reception/patients', $data)->assertRedirect();

        $patient = Patient::query()->sole();
        $this->assertNull($patient->birth_date);
        $this->assertTrue($patient->birth_date_is_approximate);
        $this->assertSame(36, $patient->declared_age);
        $this->assertNotNull($patient->declared_age_at);
    }

    public function test_store_marks_an_emergency_arrival_and_orients_it_immediately(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post('/reception/patients', [
            ...$this->patientData(),
            'is_emergency' => true,
        ]);

        $episode = Episode::first();
        $response->assertRedirect("/reception/passages/{$episode->uuid}/prestations");
        $response->assertSessionHas('status', "Passage urgence {$episode->episode_number} créé ; Soins et Médecine sont déjà alertés.");
        $this->assertSame(EpisodePriority::Emergency, $episode->priority);
        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $episode->administrative_status);
    }

    public function test_store_can_mark_an_existing_patient_arrival_as_emergency(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);

        $this->actingAs($user)->post('/reception/patients', [
            'patient_uuid' => $patient->uuid,
            'is_emergency' => true,
        ]);

        $episode = $patient->episodes()->first();
        $this->assertSame(EpisodePriority::Emergency, $episode->priority);
        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $episode->administrative_status);
    }

    public function test_store_rejects_an_invalid_emergency_flag(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);

        $response = $this->actingAs($user)->post('/reception/patients', [
            ...$this->patientData(),
            'is_emergency' => 'not-a-boolean',
        ]);

        $response->assertSessionHasErrors('is_emergency');
        $this->assertSame(0, Episode::count());
    }

    public function test_store_flashes_duplicates_and_creates_nothing(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);
        $this->actingAs($user)->post('/reception/patients', $this->patientData());

        $response = $this->actingAs($user)->post('/reception/patients', $this->patientData());

        $response->assertRedirect();
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, Episode::count());
        $this->assertNotNull(session('duplicates'));
    }

    public function test_store_creates_anyway_when_confirm_duplicate_is_sent(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);
        $this->actingAs($user)->post('/reception/patients', $this->patientData());

        $this->actingAs($user)->post('/reception/patients', [...$this->patientData(), 'confirm_duplicate' => true]);

        $this->assertSame(2, Patient::count());
        $this->assertSame(2, Episode::count());
    }

    public function test_store_validates_required_fields_when_creating_a_new_patient(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);

        $response = $this->actingAs($user)->post('/reception/patients', []);

        $response->assertSessionHasErrors(['patient_type', 'last_name', 'birth_date', 'sex']);
        $response->assertSessionDoesntHaveErrors('first_name');
    }

    public function test_store_creates_a_patient_with_a_cin(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);

        $this->actingAs($user)->post('/reception/patients', $this->patientData([
            'identity_document_type' => 'CIN',
            'identity_document_number' => '101234567890',
        ]));

        $patient = Patient::first();
        $this->assertSame('CIN', $patient->identity_document_type->value);
        $this->assertSame('101234567890', $patient->identity_document_number);
    }

    public function test_store_requires_a_document_number_when_a_document_type_is_given(): void
    {
        $user = $this->userWithPermissions(['patients.create', 'episodes.create']);

        $response = $this->actingAs($user)->post('/reception/patients', $this->patientData([
            'identity_document_type' => 'CIN',
        ]));

        $response->assertSessionHasErrors(['identity_document_number']);
    }

    public function test_store_rejects_an_existing_patient_uuid_that_does_not_exist(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);

        $response = $this->actingAs($user)->post('/reception/patients', ['patient_uuid' => '8c6ac11e-ece1-44b3-b73b-f61a12268d7f']);

        $response->assertSessionHasErrors(['patient_uuid']);
    }
}
