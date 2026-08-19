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

    public function test_create_requires_the_patients_create_permission(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->create(['code' => 'PHARMACY', 'name' => 'Pharmacie'])->id]);

        $this->actingAs($user)->get('/patients/create')->assertForbidden();
    }

    public function test_store_creates_a_patient_and_redirects_to_its_page(): void
    {
        $user = $this->userWithPermissions(['patients.create']);
        config(['rivo.site.code' => 'M']);

        $response = $this->actingAs($user)->post('/patients', $this->patientData());

        $patient = Patient::first();
        $response->assertRedirect("/patients/{$patient->id}");
        $this->assertSame('Jean', $patient->first_name);
        $this->assertSame('M-000001', $patient->patient_number);
    }

    public function test_store_flashes_duplicates_instead_of_creating_when_a_match_exists(): void
    {
        $user = $this->userWithPermissions(['patients.create']);
        $this->actingAs($user)->post('/patients', $this->patientData());

        $response = $this->actingAs($user)->post('/patients', $this->patientData());

        $response->assertRedirect();
        $this->assertSame(1, Patient::count());
        $this->assertNotNull(session('duplicates'));
    }

    public function test_store_creates_anyway_when_confirm_duplicate_is_sent(): void
    {
        $user = $this->userWithPermissions(['patients.create']);
        $this->actingAs($user)->post('/patients', $this->patientData());

        $this->actingAs($user)->post('/patients', [...$this->patientData(), 'confirm_duplicate' => true]);

        $this->assertSame(2, Patient::count());
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['patients.create']);

        $response = $this->actingAs($user)->post('/patients', []);

        $response->assertSessionHasErrors(['first_name', 'last_name', 'birth_date', 'sex']);
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
