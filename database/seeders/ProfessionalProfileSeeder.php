<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProfessionalProfileSeeder extends Seeder
{
    /**
     * A profile describes the user's principal job and proposes permissions
     * to assign to an individual account. These permissions are never
     * inherited dynamically from the profile.
     *
     * @var array<string, array<string, array{name: string, description: string, permissions: array<int, string>}>>
     */
    public const PROFILES = [
        'NURSE' => [
            'REGISTERED_NURSE' => [
                'name' => 'Infirmier / Infirmière',
                'description' => 'Soins infirmiers, constantes et exécution des actes autorisés.',
                'permissions' => [],
            ],
            'MIDWIFE' => [
                'name' => 'Sage-femme',
                'description' => 'Soins infirmiers et activité de maternité selon les droits du compte.',
                'permissions' => [],
            ],
            'ANESTHETIST' => [
                'name' => 'Anesthésiste',
                'description' => 'Soins et anesthésie au bloc selon l’affectation individuelle.',
                'permissions' => [
                    'surgery.view',
                    'anesthesia.view', 'anesthesia.create',
                    'anesthesia.update', 'anesthesia.validate',
                ],
            ],
        ],
        'SUPPORT' => [
            'GUARD' => [
                'name' => 'Gardien / Gardienne',
                'description' => 'Suivi des entrées, sorties et visiteurs du site.',
                'permissions' => [
                    'guarding.view',
                    'guarding.entries.view', 'guarding.entries.create',
                    'guarding.entries.update', 'guarding.entries.close',
                    'guarding.reports.view', 'guarding.reports.export',
                    'visitors.view', 'visitors.create', 'visitors.update', 'visitors.close',
                ],
            ],
            'CLEANER' => [
                'name' => 'Agent d’entretien / Femme de ménage',
                'description' => 'Entretien et hygiène des locaux ; aucun accès logiciel ajouté par défaut.',
                'permissions' => [],
            ],
        ],
        'MAINTENANCE' => [
            'IT_TECHNICIAN' => [
                'name' => 'Technicien informatique',
                'description' => 'Support informatique et suivi des équipements autorisés.',
                'permissions' => [
                    'logistics.view',
                    'equipment.view', 'equipment.update',
                    'equipment.maintenance.manage',
                ],
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::PROFILES as $roleCode => $profiles) {
                $role = Role::query()->where('code', $roleCode)->first();

                if (! $role) {
                    continue;
                }

                foreach ($profiles as $code => $definition) {
                    $profile = ProfessionalProfile::query()->updateOrCreate(
                        ['code' => $code],
                        [
                            'role_id' => $role->id,
                            'name' => $definition['name'],
                            'description' => $definition['description'],
                            'active' => true,
                        ],
                    );

                    $permissions = Permission::query()
                        ->whereIn('name', $definition['permissions'])
                        ->get(['id', 'name']);
                    $missingPermissions = collect($definition['permissions'])
                        ->diff($permissions->pluck('name'));

                    if ($missingPermissions->isNotEmpty()) {
                        throw new \LogicException(sprintf(
                            'Permissions recommandées inconnues pour le profil %s : %s',
                            $code,
                            $missingPermissions->implode(', '),
                        ));
                    }

                    $profile->recommendedPermissions()->sync($permissions->pluck('id'));
                }
            }

            $this->migrateLegacyGuardAccounts();
        });
    }

    /**
     * GUARD used to be a global role. Preserve each existing guard's access
     * as explicit per-account ALLOW records, then move the account to the new
     * SUPPORT/GUARD classification. No permission is granted to every SUPPORT
     * account through the role.
     */
    private function migrateLegacyGuardAccounts(): void
    {
        $legacyRole = Role::query()->where('code', 'GUARD')->lockForUpdate()->first();
        $supportRole = Role::query()->where('code', 'SUPPORT')->first();
        $guardProfile = ProfessionalProfile::query()->where('code', 'GUARD')->first();

        if (! $legacyRole || ! $supportRole || ! $guardProfile) {
            return;
        }

        $permissionIds = $legacyRole->permissions()->pluck('permissions.id');
        $now = now();

        DB::table('users')
            ->where('role_id', $legacyRole->id)
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($permissionIds, $supportRole, $guardProfile, $now): void {
                foreach ($users as $user) {
                    foreach ($permissionIds as $permissionId) {
                        DB::table('user_permissions')->insertOrIgnore([
                            'user_id' => $user->id,
                            'permission_id' => $permissionId,
                            'effect' => 'allow',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('users')->where('id', $user->id)->update([
                        'role_id' => $supportRole->id,
                        'professional_profile_id' => $guardProfile->id,
                        'updated_at' => $now,
                    ]);
                }
            });

        $legacyRole->permissions()->detach();

        if (! $legacyRole->users()->exists()) {
            $legacyRole->delete();
        }
    }
}
