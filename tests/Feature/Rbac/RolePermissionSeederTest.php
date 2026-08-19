<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedRbac(): void
    {
        (new RoleSeeder)->run();
        (new PermissionSeeder)->run();
        (new RolePermissionSeeder)->run();
    }

    private function permissionNamesFor(string $roleCode): array
    {
        return Role::query()->where('code', $roleCode)->first()
            ->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_super_admin_gets_every_permission(): void
    {
        $this->seedRbac();

        $this->assertSame(
            Permission::query()->pluck('name')->sort()->values()->all(),
            $this->permissionNamesFor('SUPER_ADMIN'),
        );
    }

    public function test_administration_only_gets_user_permissions(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('ADMINISTRATION');

        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertStringStartsWith('users.', $name);
        }
    }

    public function test_administration_does_not_leak_medical_or_patient_permissions(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('ADMINISTRATION');

        $this->assertNotContains('patients.view', $names);
        $this->assertNotContains('consultations.view', $names);
        $this->assertNotContains('patients.medical_history.manage', $names);
    }

    public function test_reception_gets_patient_and_episode_permissions_including_medical_history(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('RECEPTION');

        $this->assertContains('patients.view', $names);
        $this->assertContains('patients.medical_history.manage', $names);
        $this->assertContains('episodes.create', $names);
        $this->assertNotContains('consultations.view', $names);
    }

    public function test_medicine_gets_clinical_permissions_and_read_only_patient_episode_access(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('MEDICINE');

        $this->assertContains('consultations.create', $names);
        $this->assertContains('diagnoses.create', $names);
        $this->assertContains('prescriptions.cancel', $names);
        $this->assertContains('patients.medical_history.manage', $names);
        $this->assertContains('patients.view', $names);
        $this->assertContains('episodes.view', $names);

        // Exact-match matchers must not leak into unrelated permissions
        // that merely share the same prefix.
        $this->assertNotContains('patients.view_deleted', $names);
        $this->assertNotContains('patients.delete', $names);
        $this->assertNotContains('episodes.cancel', $names);
    }
}
