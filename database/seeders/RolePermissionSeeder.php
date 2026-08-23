<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class RolePermissionSeeder extends Seeder
{
    /**
     * Exact permission names or "prefix." globs per role — explicit,
     * because the naive "grant every permission that exists" this used to
     * do (before Patient/Episode/Médecine permissions existed) would now
     * leak medical records and episodes to ADMINISTRATION, a role CDCF
     * §21's profile table scopes to "paramétrage autorisé" only.
     *
     * @var array<string, array<int, string>>
     */
    private const GRANTS = [
        'ADMINISTRATION' => [
            'employees.view', 'employees.create', 'employees.update',
            'employees.delete', 'employees.restore',
            'employees.patient_lookup',
            'patient_staff_links.view', 'patient_staff_links.create', 'patient_staff_links.end',
            'address_entries.view', 'address_entries.create', 'address_entries.update', 'address_entries.archive',
            'mutual_organizations.view', 'mutual_organizations.create',
            'mutual_organizations.update', 'mutual_organizations.archive',
            'patient_coverages.view', 'patient_coverages.update', 'patient_coverages.end',
            'patient_coverage_documents.view', 'patient_coverage_documents.archive',
            'contracts.view', 'contracts.create', 'contracts.update', 'contracts.archive',
            'attendance.view', 'attendance.create', 'attendance.update',
            'leave.view', 'leave.create', 'leave.approve', 'leave.cancel',
            'planning.view', 'planning.create', 'planning.update',
            'hr_reports.view', 'hr_reports.export',
        ],
        'LOGISTICS' => [
            'logistics.view', 'logistics.manage',
            'administrative_stock.view', 'administrative_stock.entry',
            'administrative_stock.exit', 'administrative_stock.inventory',
            'equipment.view', 'equipment.create', 'equipment.update',
            'equipment.delete', 'equipment.restore', 'equipment.assign',
            'equipment.inventory', 'equipment.maintenance.manage',
            'equipment.decommission',
        ],
        // SUPPORT and MAINTENANCE contain distinct jobs. Their task-specific
        // permissions are copied to each account explicitly from its profile
        // template; they are never inherited globally by every role member.
        'SUPPORT' => [],
        'MAINTENANCE' => [],
        'RECEPTION' => [
            'reception.view',
            'employees.patient_lookup',
            'patient_staff_links.view', 'patient_staff_links.create',
            'address_entries.view', 'address_entries.create',
            'mutual_organizations.view', 'mutual_organizations.create',
            'patient_coverages.view', 'patient_coverages.create',
            'patient_coverage_documents.view', 'patient_coverage_documents.create',
            'visitors.view', 'visitors.create', 'visitors.close',
            'patients.view', 'patients.create', 'patients.update', 'patients.delete',
            'patients.restore', 'patients.view_deleted',
            'patients.medical_history.view', 'patients.medical_history.manage',
            'episodes.view', 'episodes.create', 'episodes.update', 'episodes.cancel',
            'billing.view', 'billing.create', 'billing.validate',
            'billing.print',
            'payments.view', 'payments.create', 'payments.cancel',
            'cash.view', 'cash.open', 'cash.close',
            'receipts.view', 'receipts.print',
        ],
        'MEDICINE' => [
            'medical_record.view',
            'consultations.view', 'consultations.create', 'consultations.update',
            'consultations.delete', 'consultations.restore',
            'diagnoses.view', 'diagnoses.create', 'diagnoses.update',
            'prescriptions.view', 'prescriptions.create', 'prescriptions.update',
            'medicines.view', 'stock.availability.view',
            'prescriptions.cancel', 'medical_discharge.create', 'patients.medical_history.view',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
        ],
        // Shared baseline for every paramedical profile. Anesthesia belongs
        // only to accounts explicitly assigned those permissions (normally
        // the ANESTHETIST profile), never to the whole NURSE role.
        'NURSE' => [
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update', 'medical_orders.view',
            'patients.medical_history.view',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
        ],
        // Surgery keeps its module baseline. A NURSE/ANESTHETIST account may
        // receive the same anesthesia permissions through individual ALLOWs.
        'SURGERY' => [
            'surgery.view', 'surgery.create', 'surgery.update', 'surgery.schedule',
            'surgery.preoperative.view', 'surgery.preoperative.validate',
            'surgery.intervention.create', 'surgery.intervention.update',
            'surgery.report.create', 'surgery.report.update', 'surgery.report.validate',
            'surgery.complications.create', 'surgery.discharge.create',
            'surgery.preparation.update', 'surgery.consumables.create',
            'surgery.care.create', 'surgery.postoperative_care.create',
            'anesthesia.view', 'anesthesia.create', 'anesthesia.update',
            'anesthesia.validate', 'episodes.view',
        ],
        // Pharmacy owns medication stock operations, never cash or payment.
        // medicines.create/update and every catalog/tariff mutation remain
        // reserved to Super Admin by ADR-024.
        'PHARMACY' => [
            'pharmacy.view', 'pharmacy.dispense', 'pharmacy.return',
            'pharmacy.reports.view', 'pharmacy.reports.export',
            'prescriptions.view', 'medicines.view', 'stock.availability.view',
            'stock.view', 'stock.entry', 'stock.exit', 'stock.adjust',
            'stock.inventory', 'stock.validate', 'stock.transfer',
            'stock.approve', 'stock.import', 'stock.export',
            'stock.lots.view', 'stock.lots.create', 'stock.lots.update',
            'stock.expiration.view',
        ],
        // The laboratory interface is not implemented yet. Keeping this
        // array explicit removes any stale grant left by older seeds.
        'LABORATORY' => [],
    ];

    public function run(): void
    {
        $superAdminPermissionIds = config('rivo.site.type') === 'admin'
            ? Permission::query()->pluck('id')
            : collect();

        Role::query()->where('code', 'SUPER_ADMIN')->first()
            ?->permissions()->sync($superAdminPermissionIds);

        foreach (self::GRANTS as $code => $matchers) {
            $ids = $this->matchingPermissionIds($matchers);

            Role::query()->where('code', $code)->first()
                ?->permissions()->sync($ids);
        }
    }

    /**
     * A matcher ending in "." is a prefix glob (e.g. "patients." matches
     * "patients.view", "patients.medical_history.manage", ...). Anything
     * else must match exactly — "patients.view" must not also pull in
     * "patients.view_deleted" through a loose LIKE.
     *
     * @param  array<int, string>  $matchers
     */
    private function matchingPermissionIds(array $matchers): Collection
    {
        if ($matchers === []) {
            return collect();
        }

        return Permission::query()
            ->where(function ($query) use ($matchers) {
                foreach ($matchers as $matcher) {
                    if (str_ends_with($matcher, '.')) {
                        $query->orWhere('name', 'like', $matcher.'%');
                    } else {
                        $query->orWhere('name', $matcher);
                    }
                }
            })
            ->pluck('id');
    }
}
