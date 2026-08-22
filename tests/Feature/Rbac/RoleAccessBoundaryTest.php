<?php

namespace Tests\Feature\Rbac;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    private function patient(string $number = 'M-000001'): Patient
    {
        return Patient::query()->create([
            'patient_number' => $number,
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
    }

    public function test_each_role_is_confined_to_its_authorized_module_routes(): void
    {
        $boundaries = [
            'RECEPTION' => [
                'allowed' => ['/reception', '/cash', '/patients'],
                'denied' => ['/surgery', '/administration/users', '/administration/catalog'],
            ],
            'MEDICINE' => [
                'allowed' => ['/patients'],
                'denied' => ['/reception', '/cash', '/surgery', '/administration/users', '/administration/catalog'],
            ],
            'NURSE' => [
                'allowed' => ['/patients'],
                'denied' => ['/reception', '/cash', '/surgery', '/administration/users', '/administration/catalog'],
            ],
            'SURGERY' => [
                'allowed' => ['/surgery'],
                'denied' => ['/reception', '/cash', '/patients', '/administration/users', '/administration/catalog'],
            ],
            'ADMINISTRATION' => [
                'allowed' => ['/administration'],
                'denied' => ['/logistics', '/reception/visitors', '/pharmacy', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
            'LOGISTICS' => [
                'allowed' => ['/logistics'],
                'denied' => ['/administration', '/reception/visitors', '/pharmacy', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
            'GUARD' => [
                'allowed' => ['/reception/visitors'],
                'denied' => ['/administration', '/logistics', '/pharmacy', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
            'PHARMACY' => [
                'allowed' => ['/pharmacy'],
                'denied' => ['/administration', '/logistics', '/reception/visitors', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
        ];

        foreach ($boundaries as $roleCode => $routes) {
            $user = $this->user($roleCode);

            foreach ($routes['allowed'] as $route) {
                $this->actingAs($user)->get($route)->assertOk();
            }

            foreach ($routes['denied'] as $route) {
                $this->actingAs($user)->get($route)->assertForbidden();
            }
        }
    }

    public function test_patient_view_update_and_delete_actions_follow_the_seeded_role_permissions(): void
    {
        $reception = $this->user('RECEPTION');
        $patient = $this->patient();

        $this->actingAs($reception)->get('/patients')->assertOk();
        $this->actingAs($reception)->get("/patients/{$patient->uuid}")->assertOk();
        $this->actingAs($reception)->get("/patients/{$patient->uuid}/edit")->assertOk();
        $this->actingAs($reception)->put("/patients/{$patient->uuid}", [
            'first_name' => 'Jeanne',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'F',
        ])->assertRedirect("/patients/{$patient->uuid}");

        $patientToDelete = $this->patient('M-000002');
        $this->actingAs($reception)->delete("/patients/{$patientToDelete->uuid}", [
            'reason' => 'Dossier créé en double',
        ])->assertRedirect();
        $this->assertSoftDeleted('patients', ['id' => $patientToDelete->id]);

        foreach (['MEDICINE', 'NURSE'] as $roleCode) {
            $viewer = $this->user($roleCode);

            $this->actingAs($viewer)->get('/patients')->assertOk();
            $this->actingAs($viewer)->get("/patients/{$patient->uuid}")->assertOk();
            $this->actingAs($viewer)->get("/patients/{$patient->uuid}/edit")->assertForbidden();
            $this->actingAs($viewer)->put("/patients/{$patient->uuid}", [])->assertForbidden();
            $this->actingAs($viewer)->delete("/patients/{$patient->uuid}", [
                'reason' => 'Tentative non autorisée',
            ])->assertForbidden();
        }

        foreach (['ADMINISTRATION', 'LOGISTICS', 'GUARD', 'SURGERY', 'PHARMACY', 'LABORATORY'] as $roleCode) {
            $unauthorized = $this->user($roleCode);

            $this->actingAs($unauthorized)->get('/patients')->assertForbidden();
            $this->actingAs($unauthorized)->get("/patients/{$patient->uuid}")->assertForbidden();
            $this->actingAs($unauthorized)->get("/patients/{$patient->uuid}/edit")->assertForbidden();
            $this->actingAs($unauthorized)->delete("/patients/{$patient->uuid}", [
                'reason' => 'Tentative non autorisée',
            ])->assertForbidden();
        }
    }

    public function test_an_individual_deny_hides_role_grants_from_direct_patient_routes(): void
    {
        $reception = $this->user('RECEPTION');
        $patient = $this->patient();

        $deniedPermissions = Permission::query()
            ->whereIn('name', ['patients.view', 'patients.update', 'patients.delete'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['effect' => 'deny']])
            ->all();

        $reception->permissions()->attach($deniedPermissions);

        $this->actingAs($reception)->get('/patients')->assertForbidden();
        $this->actingAs($reception)->get("/patients/{$patient->uuid}")->assertForbidden();
        $this->actingAs($reception)->get("/patients/{$patient->uuid}/edit")->assertForbidden();
        $this->actingAs($reception)->delete("/patients/{$patient->uuid}", [
            'reason' => 'Tentative non autorisée',
        ])->assertForbidden();

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'deleted_at' => null,
        ]);
    }
}
