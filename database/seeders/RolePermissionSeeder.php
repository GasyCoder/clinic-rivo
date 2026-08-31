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
            'employees.import', 'employees.export', 'employees.print',
            'employees.patient_lookup',
            'staff_block_credits.view', 'staff_block_credits.allocate',
            'patient_staff_links.view', 'patient_staff_links.create', 'patient_staff_links.end',
            'address_entries.view', 'address_entries.create', 'address_entries.update',
            'address_entries.archive',
            'address_entries.import', 'address_entries.export',
            'mutual_organizations.view', 'mutual_organizations.create',
            'mutual_organizations.update', 'mutual_organizations.archive',
            'mutual_organizations.import',
            'mutual_organizations.export',
            'patient_coverages.view', 'patient_coverages.update', 'patient_coverages.end',
            'patient_coverage_documents.view', 'patient_coverage_documents.archive',
            'contracts.view', 'contracts.create', 'contracts.update', 'contracts.archive',
            'contracts.restore', 'contracts.export', 'contracts.print',
            'attendance.view', 'attendance.create', 'attendance.update',
            'attendance.export', 'attendance.print',
            'leave.view', 'leave.create', 'leave.approve', 'leave.reject',
            'leave.cancel', 'leave.print',
            'planning.view', 'planning.create', 'planning.update',
            'planning.export', 'planning.print',
            'hr_settings.view', 'hr_settings.create', 'hr_settings.update',
            'hr_settings.archive', 'hr_settings.restore',
            'hr_documents.view', 'hr_documents.create',
            'hr_documents.archive', 'hr_documents.restore',
            'hr_reports.view', 'hr_reports.export', 'hr_reports.print',
            'cash_registers.view', 'cash_registers.create', 'cash_registers.update',
            'cash_registers.activate', 'cash_registers.deactivate',
            'cash_registers.archive',
            'diagnostic_catalog.view', 'diagnostic_catalog.manage',
            'analysis_catalog.view', 'analysis_catalog.create', 'analysis_catalog.update',
            'analysis_catalog.activate', 'analysis_catalog.deactivate',
            'analysis_catalog.import', 'analysis_catalog.export',
        ],
        'LOGISTICS' => [
            'logistics.view', 'logistics.manage',
            'administrative_stock.view', 'administrative_stock.entry',
            'administrative_stock.exit', 'administrative_stock.inventory',
            'equipment.view', 'equipment.create', 'equipment.update',
            'equipment.delete', 'equipment.assign',
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
            'partner_organizations.view',
            'patient_coverages.view', 'patient_coverages.create',
            'patient_coverage_documents.view', 'patient_coverage_documents.create',
            'visitors.view', 'visitors.create', 'visitors.close',
            'patients.view', 'patients.create', 'patients.update', 'patients.delete',
            'patients.medical_history.view', 'patients.medical_history.manage',
            'episodes.view', 'episodes.create', 'episodes.update', 'episodes.mark_emergency', 'episodes.cancel',
            'billing.view', 'billing.create', 'billing.validate',
            'billing.print',
            'payments.view', 'payments.create', 'payments.cancel',
            'cash.view', 'cash.open', 'cash.close', 'cash_registers.view',
            'receipts.view', 'receipts.print',
        ],
        'MEDICINE' => [
            'medical_record.view',
            'consultations.view', 'consultations.create', 'consultations.update',
            'consultations.delete',
            'diagnoses.view', 'diagnoses.create', 'diagnoses.update',
            'prescriptions.view', 'prescriptions.create', 'prescriptions.update',
            'medicines.view', 'stock.availability.view',
            'prescriptions.cancel', 'medical_discharge.create', 'patients.medical_history.view',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
            // Le médecin peut requalifier ce passage précis pendant la
            // consultation ; ce droit ne modifie jamais le Patient.
            'episodes.mark_emergency',
            // Same read-only projection of the Soins worksheet Surgery reads
            // through CareRecordReadModel (ADR-048): view only, never
            // care.update/vitals.update — Médecine never edits the fiche.
            'care.view', 'vitals.view',
            // Phase B: a doctor may request Soins acts from a consultation,
            // never edit the resulting fiche itself.
            'care_orders.create', 'care_orders.view',
            // Paraclinique/orientation requests only — never the receiving
            // module's own create/manage permission (surgery.create stays
            // reserved to SURGERY; laboratory_results.create to LABORATORY).
            'laboratory_orders.create', 'laboratory_orders.view', 'laboratory_results.view',
            'imaging_orders.create', 'imaging_orders.view', 'imaging_results.create',
            'surgery.request', 'hospitalization.request', 'maternity.request',
            'transfer.request', 'pediatrics.request',
        ],
        // Shared baseline for every paramedical profile. Anesthesia belongs
        // only to accounts explicitly assigned those permissions (normally
        // the ANESTHETIST profile), never to the whole NURSE role.
        'NURSE' => [
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update', 'medical_orders.view',
            'patients.medical_history.view',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
            // Reads a doctor's Soins request — never creates one itself.
            'care_orders.view',
        ],
        // SURGERY is the surgeon/operating-team baseline. Access to the
        // separate Anesthesia workspace is granted explicitly per account;
        // it is never implied by surgery.view (ADR-048).
        'SURGERY' => [
            'surgery.view', 'surgery.create', 'surgery.update', 'surgery.schedule',
            'surgery.preoperative.view', 'surgery.preoperative.validate',
            'surgery.intervention.create', 'surgery.intervention.update',
            'surgery.report.create', 'surgery.report.update', 'surgery.report.validate',
            'surgery.complications.create', 'surgery.discharge.create',
            'surgery.preparation.update', 'surgery.consumables.create',
            'surgery.care.create', 'surgery.postoperative_care.create',
            // The Soins worksheet is reused in read-only mode during block
            // preparation; no care/vitals/history mutation is granted here.
            'care.view', 'vitals.view', 'patients.medical_history.view',
            'episodes.view',
        ],
        // Pharmacy owns medication stock operations, never cash or payment.
        // medicines.create/update and every catalog/tariff mutation remain
        // reserved to Super Admin by ADR-024.
        'PHARMACY' => [
            'pharmacy.view', 'pharmacy.dispense', 'pharmacy.dispense.prepare_invoice', 'pharmacy.dispense.print',
            'pharmacy.counter_sales.create', 'pharmacy.return',
            'pharmacy.reports.view', 'pharmacy.reports.export',
            'prescriptions.view', 'medicines.view', 'medicine_categories.view',
            'medicine_suppliers.view', 'stock.availability.view',
            'stock.view', 'stock.entry', 'stock.exit', 'stock.adjust',
            'stock.inventory', 'stock.validate', 'stock.transfer',
            'stock.approve', 'stock.import', 'stock.export',
            'stock.lots.view', 'stock.lots.create', 'stock.lots.update',
            'stock.expiration.view', 'stock.alerts.view',
            'stock.cost.view', 'stock.cost.record',
        ],
        // Minimal follow-through only (request tracking + result entry) —
        // sample/analysis workflow itself remains unbuilt.
        'LABORATORY' => [
            'laboratory_orders.view', 'laboratory_results.view', 'laboratory_results.create',
        ],
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
