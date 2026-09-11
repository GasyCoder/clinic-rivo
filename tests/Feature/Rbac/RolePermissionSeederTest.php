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

    public function test_super_admin_gets_every_permission_only_on_the_admin_deployment(): void
    {
        config(['rivo.site.type' => 'admin']);
        $this->seedRbac();

        $this->assertSame(
            Permission::query()->pluck('name')->sort()->values()->all(),
            $this->permissionNamesFor('SUPER_ADMIN'),
        );
    }

    public function test_super_admin_gets_no_site_permission_on_a_clinic_deployment(): void
    {
        config(['rivo.site.type' => 'clinic']);
        $this->seedRbac();

        $this->assertSame([], $this->permissionNamesFor('SUPER_ADMIN'));
    }

    public function test_contract_template_permission_migration_never_grants_super_admin_on_a_clinic_deployment(): void
    {
        // Simulates the real deployment order: roles/permissions already
        // exist from the initial `db:seed` (ADR-064: never re-run after
        // go-live), then this later, standalone migration runs on top of
        // that already-populated `roles` table — exactly the scenario the
        // migration exists for.
        config(['rivo.site.type' => 'clinic']);
        (new RoleSeeder)->run();
        (new PermissionSeeder)->run();
        (new RolePermissionSeeder)->run();

        $migration = require database_path('migrations/2026_09_01_160000_register_contract_template_permissions.php');
        $migration->up();

        $this->assertSame([], $this->permissionNamesFor('SUPER_ADMIN'));
        $this->assertContains('contract_templates.view', $this->permissionNamesFor('ADMINISTRATION'));
        $this->assertContains('contracts.download', $this->permissionNamesFor('ADMINISTRATION'));
    }

    public function test_contract_template_permission_migration_grants_super_admin_on_the_admin_deployment(): void
    {
        config(['rivo.site.type' => 'admin']);
        (new RoleSeeder)->run();
        (new PermissionSeeder)->run();
        (new RolePermissionSeeder)->run();

        $migration = require database_path('migrations/2026_09_01_160000_register_contract_template_permissions.php');
        $migration->up();

        $this->assertContains('contract_templates.view', $this->permissionNamesFor('SUPER_ADMIN'));
        $this->assertContains('contracts.download', $this->permissionNamesFor('SUPER_ADMIN'));
    }

    public function test_administration_gets_hr_permissions_only_without_logistics_guarding_or_access_management(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('ADMINISTRATION');

        $this->assertContains('employees.view', $names);
        $this->assertContains('contracts.create', $names);
        $this->assertContains('attendance.update', $names);
        $this->assertContains('leave.approve', $names);
        $this->assertContains('planning.update', $names);
        $this->assertContains('hr_reports.export', $names);
        $this->assertNotContains('logistics.manage', $names);
        $this->assertNotContains('administrative_stock.inventory', $names);
        $this->assertNotContains('guarding.view', $names);
        $this->assertNotContains('visitors.view', $names);
        $this->assertNotContains('users.view', $names);
        $this->assertNotContains('roles.view', $names);
        $this->assertNotContains('permissions.assign', $names);
        $this->assertNotContains('users.assign_super_admin', $names);
    }

    public function test_logistics_gets_equipment_and_administrative_stock_permissions_only(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('LOGISTICS');

        $this->assertContains('logistics.manage', $names);
        $this->assertContains('equipment.inventory', $names);
        $this->assertContains('equipment.assign', $names);
        $this->assertContains('equipment.maintenance.manage', $names);
        $this->assertContains('equipment.decommission', $names);
        $this->assertContains('administrative_stock.inventory', $names);
        $this->assertNotContains('stock.inventory', $names);
        $this->assertNotContains('medicines.view', $names);
        $this->assertNotContains('visitors.create', $names);
        $this->assertNotContains('employees.update', $names);
    }

    public function test_support_and_maintenance_have_no_global_task_permission(): void
    {
        $this->seedRbac();

        $this->assertSame([], $this->permissionNamesFor('SUPPORT'));
        $this->assertSame([], $this->permissionNamesFor('MAINTENANCE'));
    }

    public function test_administration_does_not_leak_medical_or_patient_permissions(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('ADMINISTRATION');

        $this->assertNotContains('patients.view', $names);
        $this->assertNotContains('consultations.view', $names);
        $this->assertNotContains('patients.medical_history.manage', $names);
        $this->assertNotContains('super_admin.portal.view', $names);
    }

    public function test_reception_gets_patient_and_episode_permissions_including_medical_history(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('RECEPTION');

        $this->assertContains('reception.view', $names);
        $this->assertContains('visitors.view', $names);
        $this->assertContains('visitors.create', $names);
        $this->assertContains('visitors.close', $names);
        $this->assertContains('patients.view', $names);
        $this->assertContains('patients.update', $names);
        $this->assertContains('patients.delete', $names);
        $this->assertContains('patients.medical_history.manage', $names);
        $this->assertContains('episodes.create', $names);
        $this->assertContains('episodes.mark_emergency', $names);
        $this->assertContains('billing.create', $names);
        $this->assertContains('payments.create', $names);
        $this->assertContains('payments.cancel', $names);
        $this->assertContains('cash.open', $names);
        $this->assertContains('cash.close', $names);
        $this->assertContains('receipts.print', $names);
        $this->assertNotContains('patients.force_delete', $names);
        $this->assertNotContains('consultations.view', $names);
    }

    public function test_medicine_gets_clinical_permissions_and_read_only_patient_episode_access(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('MEDICINE');

        $this->assertContains('consultations.create', $names);
        $this->assertContains('medical_record.view', $names);
        $this->assertContains('diagnoses.create', $names);
        $this->assertContains('prescriptions.cancel', $names);
        $this->assertContains('medicines.view', $names);
        $this->assertContains('stock.availability.view', $names);
        $this->assertContains('medical_discharge.create', $names);
        $this->assertContains('patients.medical_history.manage', $names);
        $this->assertContains('patients.view', $names);
        $this->assertContains('episodes.view', $names);
        $this->assertContains('episodes.mark_emergency', $names);
        $this->assertContains('care_orders.create', $names);
        $this->assertContains('care_orders.view', $names);

        // Exact-match matchers must not leak into unrelated permissions
        // that merely share the same prefix.
        $this->assertNotContains('patients.view_deleted', $names);
        $this->assertNotContains('patients.update', $names);
        $this->assertNotContains('patients.delete', $names);
        $this->assertNotContains('episodes.cancel', $names);
    }

    public function test_nurse_gets_shared_care_and_vitals_but_not_profile_specific_anesthesia(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('NURSE');

        $this->assertContains('care.create', $names);
        $this->assertContains('vitals.create', $names);
        $this->assertContains('medical_orders.view', $names);
        $this->assertContains('patients.view', $names);
        $this->assertContains('patients.medical_history.manage', $names);
        $this->assertContains('care_orders.view', $names);

        // No CDC-defined "maternité" catalog exists — nothing to grant.
        $this->assertNotContains('consultations.create', $names);
        $this->assertNotContains('prescriptions.create', $names);
        $this->assertNotContains('anesthesia.view', $names);
        $this->assertNotContains('anesthesia.validate', $names);
        $this->assertNotContains('patients.update', $names);
        $this->assertNotContains('patients.delete', $names);
        // NURSE reads a doctor's order, never creates one (Phase B).
        $this->assertNotContains('care_orders.create', $names);
    }

    public function test_no_business_or_administration_role_can_collect_money(): void
    {
        $this->seedRbac();

        foreach (['ADMINISTRATION', 'LOGISTICS', 'SUPPORT', 'MAINTENANCE', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'] as $roleCode) {
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

    public function test_only_super_admin_receives_catalog_and_tariff_management_by_default(): void
    {
        config(['rivo.site.type' => 'admin']);
        $this->seedRbac();

        foreach (['ADMINISTRATION', 'LOGISTICS', 'SUPPORT', 'MAINTENANCE', 'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'] as $roleCode) {
            foreach ($this->permissionNamesFor($roleCode) as $permission) {
                $this->assertFalse(
                    str_starts_with($permission, 'catalog.'),
                    "Le rôle {$roleCode} ne doit pas configurer le référentiel par défaut ({$permission}).",
                );
            }
        }

        $this->assertContains('catalog.items.view', $this->permissionNamesFor('SUPER_ADMIN'));
        $this->assertContains('catalog.tariffs.archive', $this->permissionNamesFor('SUPER_ADMIN'));
    }

    public function test_surgery_gets_surgical_and_episode_permissions_without_implicit_anesthesia_access(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('SURGERY');

        $this->assertContains('surgery.create', $names);
        $this->assertContains('surgery.report.validate', $names);
        $this->assertContains('care.view', $names);
        $this->assertContains('vitals.view', $names);
        $this->assertContains('patients.medical_history.view', $names);
        $this->assertNotContains('care.update', $names);
        $this->assertNotContains('vitals.update', $names);
        $this->assertNotContains('patients.medical_history.manage', $names);
        $this->assertNotContains('anesthesia.view', $names);
        $this->assertNotContains('anesthesia.create', $names);
        $this->assertNotContains('anesthesia.update', $names);
        $this->assertNotContains('anesthesia.validate', $names);
        $this->assertContains('episodes.view', $names);

        $this->assertNotContains('consultations.view', $names);
        $this->assertNotContains('prescriptions.create', $names);
        $this->assertNotContains('episodes.create', $names);
    }

    public function test_anesthesia_is_not_inherited_by_every_nurse_account(): void
    {
        $this->seedRbac();

        $nurseNames = $this->permissionNamesFor('NURSE');
        foreach (['anesthesia.view', 'anesthesia.create', 'anesthesia.update', 'anesthesia.validate'] as $permission) {
            $this->assertNotContains($permission, $nurseNames);
        }

        $surgeryNames = $this->permissionNamesFor('SURGERY');
        foreach (['anesthesia.view', 'anesthesia.create', 'anesthesia.update', 'anesthesia.validate'] as $permission) {
            $this->assertNotContains($permission, $surgeryNames);
        }
    }

    public function test_pharmacy_gets_its_stock_permissions_without_catalog_mutation_or_cash(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('PHARMACY');

        $this->assertContains('pharmacy.view', $names);
        $this->assertContains('pharmacy.dispense', $names);
        $this->assertContains('pharmacy.return', $names);
        $this->assertContains('pharmacy.reports.view', $names);
        $this->assertContains('medicines.view', $names);
        $this->assertContains('stock.availability.view', $names);
        $this->assertContains('stock.entry', $names);
        $this->assertContains('stock.exit', $names);
        $this->assertContains('stock.adjust', $names);
        $this->assertContains('stock.inventory', $names);
        $this->assertContains('stock.transfer', $names);
        $this->assertContains('stock.approve', $names);
        $this->assertContains('stock.lots.create', $names);
        $this->assertContains('stock.expiration.view', $names);
        $this->assertNotContains('medicines.create', $names);
        $this->assertNotContains('medicines.update', $names);
        $this->assertNotContains('catalog.items.update', $names);
        $this->assertNotContains('payments.create', $names);
        $this->assertNotContains('cash.view', $names);
    }

    /**
     * Phase A (Soins/Médecine stabilization) locks in the clinical/financial
     * boundaries the audit relied on, without asserting the permanent
     * absence of permissions not yet defined at all (e.g. surgery.request,
     * care_orders.create): those are simply undefined today, not
     * deliberately withheld, and must remain free to be added later.
     */
    public function test_nurse_and_medicine_stay_locked_out_of_diagnosis_stock_mutation_and_staff_credit_permissions(): void
    {
        $this->seedRbac();

        $nurseNames = $this->permissionNamesFor('NURSE');
        foreach (['diagnoses.view', 'diagnoses.create', 'diagnoses.update'] as $permission) {
            $this->assertNotContains($permission, $nurseNames);
        }
        foreach (['consultations.view', 'consultations.create', 'consultations.update'] as $permission) {
            $this->assertNotContains($permission, $nurseNames);
        }
        foreach (['staff_block_credits.view', 'staff_block_credits.allocate'] as $permission) {
            $this->assertNotContains($permission, $nurseNames);
        }

        $medicineNames = $this->permissionNamesFor('MEDICINE');
        foreach (['staff_block_credits.view', 'staff_block_credits.allocate'] as $permission) {
            $this->assertNotContains($permission, $medicineNames);
        }
        foreach (['stock.entry', 'stock.exit', 'stock.adjust', 'stock.inventory', 'stock.transfer', 'stock.approve'] as $permission) {
            $this->assertNotContains($permission, $medicineNames);
        }
        $this->assertContains('stock.availability.view', $medicineNames);
    }

    public function test_laboratory_gets_result_entry_only_never_cash_or_catalog(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('LABORATORY');

        $this->assertContains('laboratory_results.create', $names);
        $this->assertContains('laboratory_orders.view', $names);
        $this->assertNotContains('laboratory_orders.create', $names);
        $this->assertNotContains('payments.create', $names);
        $this->assertNotContains('cash.view', $names);
    }

    public function test_medicine_gets_referral_requests_never_the_receiving_modules_own_create_permission(): void
    {
        $this->seedRbac();

        $names = $this->permissionNamesFor('MEDICINE');

        $this->assertContains('laboratory_orders.create', $names);
        $this->assertContains('surgery.request', $names);
        $this->assertContains('hospitalization.request', $names);
        $this->assertContains('maternity.request', $names);
        $this->assertContains('transfer.request', $names);
        $this->assertContains('pediatrics.request', $names);
        $this->assertNotContains('surgery.create', $names);
        $this->assertNotContains('laboratory_results.create', $names);
    }

    public function test_obsolete_physical_user_delete_permission_is_removed(): void
    {
        Permission::query()->create(['name' => 'users.delete']);

        $this->seedRbac();

        $this->assertDatabaseMissing('permissions', ['name' => 'users.delete']);
    }
}
