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
        'ADMINISTRATION' => ['users.'],
        'RECEPTION' => ['patients.', 'episodes.', 'billing.', 'payments.', 'cash.', 'receipts.'],
        'MEDICINE' => ['consultations.', 'diagnoses.', 'prescriptions.', 'patients.medical_history.', 'patients.view', 'episodes.view'],
        // ADR-006 amendment 2026-08-19 — "Soins" (§15) + "Anesthésie" (§16)
        // catalogs; no "Maternité" grant since no such permission catalog
        // exists in the CDC (see PermissionSeeder).
        'NURSE' => ['care.', 'vitals.', 'medical_orders.view', 'anesthesia.', 'patients.medical_history.', 'patients.view', 'episodes.view'],
        // anesthesia. is intentionally granted to both NURSE and SURGERY —
        // see the same ADR-006 amendment ("normalement rattaché à SURGERY
        // mais explicitement demandé aussi pour NURSE").
        'SURGERY' => ['surgery.', 'anesthesia.', 'episodes.view'],
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
