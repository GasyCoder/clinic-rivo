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
        $this->assertContains('billing.create', $names);
        $this->assertContains('payments.create', $names);
        $this->assertContains('payments.cancel', $names);
        $this->assertContains('cash.open', $names);
        $this->assertContains('cash.close', $names);
        $this->assertContains('receipts.print', $names);
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

    public function test_nurse_gets_care_vitals_and_anesthesia_permissions(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('NURSE');

        $this->assertContains('care.create', $names);
        $this->assertContains('vitals.create', $names);
        $this->assertContains('medical_orders.view', $names);
        $this->assertContains('anesthesia.validate', $names);
        $this->assertContains('patients.view', $names);
        $this->assertContains('patients.medical_history.manage', $names);

        // No CDC-defined "maternité" catalog exists — nothing to grant.
        $this->assertNotContains('consultations.create', $names);
        $this->assertNotContains('prescriptions.create', $names);
    }

    public function test_no_business_or_administration_role_can_collect_money(): void
    {
        $this->seedRbac();

        foreach (['ADMINISTRATION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'] as $roleCode) {
            $names = $this->permissionNamesFor($roleCode);

            foreach ($names as $name) {
                $this->assertFalse(
                    str_starts_with($name, 'payments.')
                    || str_starts_with($name, 'cash.')
                    || str_starts_with($name, 'receipts.')
                    || str_starts_with($name, 'refunds.'),
                    "Le rôle {$roleCode} ne doit pas recevoir la permission financière {$name}.",
                );
            }
        }
    }
}
