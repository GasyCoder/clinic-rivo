<?php

namespace Tests\Feature\Http;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientControllerTest extends TestCase
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

    public function test_index_requires_the_patients_view_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie'])->id]);

        $this->actingAs($user)->get('/patients')->assertForbidden();
    }

    public function test_index_lists_patients_and_supports_search(): void
    {
        $user = $this->userWithPermissions(['patients.view']);
        Patient::create(['patient_number' => 'M-000001', ...$this->patientData(['first_name' => 'Jean', 'last_name' => 'Rakoto'])]);
        Patient::create(['patient_number' => 'M-000002', ...$this->patientData(['first_name' => 'Marie', 'last_name' => 'Rasoa'])]);

        $this->actingAs($user)->get('/patients?q=Rakoto')
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Index')
                ->has('patients.data', 1)
                ->where('patients.data.0.last_name', 'Rakoto')
            );
    }

    public function test_there_is_no_creation_route_on_the_read_only_directory(): void
    {
        $user = $this->userWithPermissions(['patients.view']);

        $this->actingAs($user)->post('/patients', $this->patientData())->assertStatus(405);
    }

    public function test_show_loads_antecedents_allergies_and_episodes(): void
    {
        $user = $this->userWithPermissions(['patients.view']);
        $patient = Patient::create(['patient_number' => 'M-000001', ...$this->patientData()]);
        $patient->antecedents()->create(['description' => 'Diabète type 2']);
        $patient->allergies()->create(['substance' => 'Pénicilline']);

        $this->actingAs($user)->get("/patients/{$patient->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Patients/Show')
                ->where('patient.id', $patient->id)
                ->has('patient.antecedents', 1)
                ->has('patient.allergies', 1)
            );
    }
}
