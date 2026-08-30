<?php

namespace Tests\Feature;

use App\Enums\PatientSex;
use App\Enums\PatientType;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Local, single-site Corbeille (ADR-065) — distinct from the Super Admin
 * portal's multi-site aggregator, already covered by
 * tests/Feature/Api/SuperAdminTrashApiTest.php and
 * tests/Feature/SuperAdmin/TrashPortalTest.php.
 */
class TrashLocalPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
        ]);
        $this->seed(PermissionSeeder::class);
    }

    public function test_a_role_with_only_trash_view_sees_a_read_only_list(): void
    {
        $role = Role::query()->create(['code' => 'MEDICINE', 'name' => 'Médecine']);
        $role->permissions()->attach(Permission::query()->where('name', 'trash.view')->value('id'));
        $user = User::factory()->create(['role_id' => $role->id]);

        $patient = $this->deletedPatient('PA-000001');

        $this->actingAs($user)
            ->get('/trash')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Trash/Index')
                ->has('records', 1)
                ->where('records.0.reference', 'PA-000001')
                // trash.restore/patients.restore stay reserved to SUPER_ADMIN
                // by default (ADR-061) — trash.view alone never unlocks it.
                ->where('records.0.can_restore', false));

        $this->actingAs($user)
            ->post("/trash/PATIENT/{$patient->uuid}/restore")
            ->assertForbidden();
    }

    public function test_a_role_with_trash_restore_and_the_category_permission_can_restore_locally(): void
    {
        $role = Role::query()->create(['code' => 'ADMINISTRATION', 'name' => 'Administration']);
        $role->permissions()->attach(Permission::query()->whereIn('name', [
            'trash.view', 'trash.restore', 'patients.restore',
        ])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);

        $patient = $this->deletedPatient('PA-000002');

        $this->actingAs($user)
            ->get('/trash')
            ->assertInertia(fn ($page) => $page->where('records.0.can_restore', true));

        $this->actingAs($user)
            ->post("/trash/PATIENT/{$patient->uuid}/restore")
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotSoftDeleted('patients', ['uuid' => $patient->uuid]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restore',
            'entity_uuid' => $patient->uuid,
            'user_id' => $user->id,
        ]);
    }

    public function test_trash_menu_link_and_route_require_trash_view(): void
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get('/trash')->assertForbidden();
    }

    private function deletedPatient(string $number): Patient
    {
        $patient = Patient::query()->create([
            'patient_number' => $number,
            'patient_type' => PatientType::Standard,
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => PatientSex::Male,
        ]);
        $patient->delete_reason = 'Dossier créé en double à la réception';
        $patient->delete();

        return $patient;
    }
}
