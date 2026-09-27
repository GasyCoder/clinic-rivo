<?php

namespace App\Actions\Role;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\Auditor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-186 — sur le portail, le rôle SUPER_ADMIN détient réellement toutes les
 * permissions.
 *
 * Les ADR-025, ADR-027 et ADR-064 l'affirment (« il reçoit automatiquement
 * toutes les permissions sur le portail »), mais seul `RolePermissionSeeder`
 * le faisait, et il ne se rejoue plus sur une base en service (ADR-064). Les
 * permissions ajoutées ensuite — par une migration qui ne pensait qu'aux rôles
 * cliniques, ou par `PermissionSeeder` seul — n'atteignaient jamais le
 * portail : le Super Admin perdait des écrans (« Canevas de documents ») et,
 * comme le portail transmet ses droits à chaque appel d'API, le site refusait
 * les commandes correspondantes.
 *
 * Cette action rétablit l'invariant à chaque `php artisan migrate` du portail :
 *
 * - chaque permission du catalogue de l'application existe dans la base ;
 * - le rôle SUPER_ADMIN les détient toutes.
 *
 * Elle n'ajoute que ce qui manque. Elle ne retire rien, ne touche à aucun
 * autre rôle et à aucune exception individuelle : un `DENY` nominatif garde
 * la priorité (ADR-033). Sur un site clinique, elle ne fait rien — le
 * SUPER_ADMIN n'y reçoit aucune permission (ADR-027).
 */
class SyncPortalSuperAdminPermissionsAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @return array{created: list<string>, granted: list<string>} */
    public function execute(): array
    {
        $nothing = ['created' => [], 'granted' => []];

        if (config('rivo.site.type') !== 'admin' || ! $this->installed()) {
            return $nothing;
        }

        $role = Role::query()->where('code', Role::PROTECTED_CODE)->first();

        if (! $role) {
            return $nothing;
        }

        $result = DB::transaction(function () use ($role): array {
            $known = array_flip(Permission::query()->pluck('name')->all());
            $created = [];

            foreach (PermissionSeeder::PERMISSIONS as $name => $label) {
                if (isset($known[$name])) {
                    continue;
                }

                Permission::query()->create(['name' => $name, 'label' => $label]);
                $created[] = $name;
            }

            $missing = Permission::query()
                ->whereNotIn('id', $role->permissions()->select('permissions.id'))
                ->orderBy('name')
                ->get(['id', 'name']);

            if ($missing->isNotEmpty()) {
                $now = now();

                $role->permissions()->syncWithoutDetaching(
                    $missing->mapWithKeys(fn (Permission $permission) => [
                        $permission->id => ['created_at' => $now, 'updated_at' => $now],
                    ])->all(),
                );
            }

            $granted = $missing->pluck('name')->values()->all();

            if ($created !== [] || $granted !== []) {
                $this->auditor->record(
                    'role.permissions.portal_sync',
                    entity: $role,
                    newValues: ['created' => $created, 'granted' => $granted],
                    module: 'administration',
                );
            }

            return ['created' => $created, 'granted' => $granted];
        });

        // Le Gate lit les noms connus depuis ce cache (AppServiceProvider).
        Cache::forget(Permission::CACHE_KEY);

        return $result;
    }

    /**
     * Une migration annulée peut avoir retiré l'une de ces tables, ou la
     * corbeille des rôles que `Role` lit à chaque requête (ADR-100).
     */
    private function installed(): bool
    {
        foreach (['permissions', 'roles', 'role_permissions', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return Schema::hasColumn('roles', 'deleted_at');
    }
}
