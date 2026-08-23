<?php

namespace Tests\Feature\Http;

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

        $this->actingAs($user)->post("/patients/{$patient->uuid}/episodes")->assertForbidden();
    }

    public function test_store_creates_an_episode_and_redirects_to_service_selection(): void
    {
        $user = $this->userWithPermissions(['episodes.create']);
        $patient = $this->makePatient();
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post("/patients/{$patient->uuid}/episodes");

        $episode = $patient->episodes()->sole();

        $response->assertRedirect("/reception/passages/{$episode->uuid}/prestations");
    }
}
