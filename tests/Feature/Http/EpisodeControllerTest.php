<?php

namespace Tests\Feature\Http;

use App\Enums\EpisodeAdministrativeStatus;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpisodeControllerTest extends TestCase
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

    private function makePatient(): Patient
    {
        return Patient::create([
            'patient_number' => 'M-000001',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    public function test_store_requires_the_episodes_create_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie'])->id]);
        $patient = $this->makePatient();

        $this->actingAs($user)->post("/patients/{$patient->id}/episodes")->assertForbidden();
    }

    public function test_store_creates_an_episode_and_redirects_to_the_patient(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = $this->makePatient();
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post("/patients/{$patient->id}/episodes");

        $response->assertRedirect("/patients/{$patient->id}");
        $this->assertSame(1, $patient->episodes()->count());
    }

    public function test_orient_requires_the_episodes_update_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie'])->id]);
        $episode = Episode::create([
            'patient_id' => $this->makePatient()->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'administrative_status' => EpisodeAdministrativeStatus::PendingOrientation,
            'started_at' => now(),
        ]);

        $this->actingAs($user)->post("/episodes/{$episode->id}/orient")->assertForbidden();
    }

    public function test_orient_transitions_the_episode(): void
    {
        $user = $this->userWithPermissions(['episodes.update']);
        $episode = Episode::create([
            'patient_id' => $this->makePatient()->id,
            'episode_number' => 'ME-000001',
            'status' => 'OPEN',
            'administrative_status' => EpisodeAdministrativeStatus::PendingOrientation,
            'started_at' => now(),
        ]);

        $this->actingAs($user)->post("/episodes/{$episode->id}/orient")->assertRedirect();

        $this->assertSame(EpisodeAdministrativeStatus::Oriented, $episode->fresh()->administrative_status);
    }
}
