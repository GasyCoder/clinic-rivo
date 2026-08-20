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
            'contracts.view', 'contracts.create', 'contracts.update', 'contracts.archive',
            'attendance.view', 'attendance.create', 'attendance.update',
            'leave.view', 'leave.create', 'leave.approve', 'leave.cancel',
            'planning.view', 'planning.create', 'planning.update',
            'logistics.view', 'logistics.manage',
            'administrative_stock.view', 'administrative_stock.entry',
            'administrative_stock.exit', 'administrative_stock.inventory',
            'visitors.view', 'hr_reports.view', 'hr_reports.export',
        ],
        'RECEPTION' => [
            'reception.view',
            'visitors.view', 'visitors.create', 'visitors.close',
            'patients.view', 'patients.create', 'patients.update', 'patients.delete',
            'patients.restore', 'patients.view_deleted',
            'patients.medical_history.view', 'patients.medical_history.manage',
            'episodes.view', 'episodes.create', 'episodes.update', 'episodes.cancel',
            'billing.view', 'billing.create', 'billing.validate',
            'payments.view', 'payments.create', 'payments.cancel',
            'cash.view', 'cash.open', 'cash.close',
            'receipts.view', 'receipts.print',
        ],
        'MEDICINE' => [
            'consultations.view', 'consultations.create', 'consultations.update',
            'consultations.delete', 'consultations.restore',
            'diagnoses.view', 'diagnoses.create', 'diagnoses.update',
            'prescriptions.view', 'prescriptions.create', 'prescriptions.update',
            'prescriptions.cancel', 'patients.medical_history.view',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
        ],
        // ADR-006 amendment 2026-08-19 — "Soins" (§15) + "Anesthésie" (§16)
        // catalogs; no "Maternité" grant since no such permission catalog
        // exists in the CDC (see PermissionSeeder).
        'NURSE' => [
            'care.view', 'care.create', 'care.update', 'care.complete',
            'vitals.view', 'vitals.create', 'vitals.update', 'medical_orders.view',
            'anesthesia.view', 'anesthesia.create', 'anesthesia.update',
            'anesthesia.validate', 'patients.medical_history.view',
            'patients.medical_history.manage', 'patients.view', 'episodes.view',
        ],
        // anesthesia. is intentionally granted to both NURSE and SURGERY —
        // see the same ADR-006 amendment ("normalement rattaché à SURGERY
        // mais explicitement demandé aussi pour NURSE").
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
        // Their business modules are not implemented yet. Keeping these
        // arrays explicit also removes any stale grants left by old seeds.
        'PHARMACY' => [],
        'LABORATORY' => [],
    ];

    public function run(): void
    {
        $allPermissionIds = Permission::query()->pluck('id');

        Role::query()->where('code', 'SUPER_ADMIN')->first()
            ?->permissions()->sync($allPermissionIds);

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
