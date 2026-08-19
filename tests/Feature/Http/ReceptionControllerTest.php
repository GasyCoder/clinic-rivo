<?php

namespace Tests\Feature\Http;

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
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            ...$overrides,
        ];
    }

    public function test_create_requires_the_episodes_create_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie'])->id]);

        $this->actingAs($user)->get('/reception')->assertForbidden();
    }

    public function test_search_returns_matching_patients(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        Patient::create(['patient_number' => 'M-000001', ...$this->patientData(['last_name' => 'Rakoto'])]);
        Patient::create(['patient_number' => 'M-000002', ...$this->patientData(['first_name' => 'Marie', 'last_name' => 'Rasoa'])]);

        $this->actingAs($user)->get('/reception?q=Rakoto')
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->has('matches', 1)
                ->where('matches.0.last_name', 'Rakoto')
            );
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

        $this->actingAs($user)->get('/reception')
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->has('recentEpisodes', 1)
                ->where('recentEpisodes.0.id', $episode->id)
                ->where('recentEpisodes.0.patient.last_name', 'Rakoto')
            );
    }

    public function test_store_with_an_existing_patient_id_only_creates_an_episode(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post('/reception', ['patient_id' => $patient->id]);

        $response->assertRedirect("/patients/{$patient->id}");
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, $patient->episodes()->count());
    }

    public function test_store_with_new_patient_data_creates_both_patient_and_episode(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post('/reception', $this->patientData());

        $patient = Patient::first();
        $response->assertRedirect("/patients/{$patient->id}");
        $this->assertSame('Jean', $patient->first_name);
        $this->assertSame(1, $patient->episodes()->count());
    }

    public function test_store_flashes_duplicates_and_creates_nothing(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $this->actingAs($user)->post('/reception', $this->patientData());

        $response = $this->actingAs($user)->post('/reception', $this->patientData());

        $response->assertRedirect();
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, Episode::count());
        $this->assertNotNull(session('duplicates'));
    }

    public function test_store_creates_anyway_when_confirm_duplicate_is_sent(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $this->actingAs($user)->post('/reception', $this->patientData());

        $this->actingAs($user)->post('/reception', [...$this->patientData(), 'confirm_duplicate' => true]);

        $this->assertSame(2, Patient::count());
        $this->assertSame(2, Episode::count());
    }

    public function test_store_validates_required_fields_when_creating_a_new_patient(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);

        $response = $this->actingAs($user)->post('/reception', []);

        $response->assertSessionHasErrors(['last_name', 'birth_date', 'sex']);
        $response->assertSessionDoesntHaveErrors('first_name');
    }

    public function test_store_rejects_an_existing_patient_id_that_does_not_exist(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);

        $response = $this->actingAs($user)->post('/reception', ['patient_id' => 999]);

        $response->assertSessionHasErrors(['patient_id']);
    }
}
