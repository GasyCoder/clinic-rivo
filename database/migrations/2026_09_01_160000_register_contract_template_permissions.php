<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'contract_templates.view' => 'Voir les modèles de contrat',
        'contract_templates.create' => 'Importer un modèle de contrat',
        'contract_templates.update' => 'Modifier ou remplacer un modèle de contrat',
        'contract_templates.archive' => 'Archiver un modèle de contrat',
        'contract_templates.restore' => 'Restaurer un modèle de contrat',
        'contract_templates.download' => 'Télécharger un modèle de contrat',
        'contracts.download' => 'Télécharger un contrat généré',
    ];

    public function up(): void
    {
        $now = now();
        $rows = collect(self::PERMISSIONS)->map(
            fn (string $label, string $name): array => compact('name', 'label') + ['created_at' => $now, 'updated_at' => $now],
        )->values()->all();
        DB::table('permissions')->upsert($rows, ['name'], ['label', 'updated_at']);

        // ADR-025/ADR-027: SUPER_ADMIN is a portal-only role. It receives every
        // permission automatically on the admin deployment and none on a clinic
        // deployment — mirrored here from RolePermissionSeeder::run() so this
        // backfill migration never grants it real access on a clinic site.
        $roleCodes = config('rivo.site.type') === 'admin'
            ? ['ADMINISTRATION', 'SUPER_ADMIN']
            : ['ADMINISTRATION'];
        $roleIds = DB::table('roles')->whereIn('code', $roleCodes)->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore($permissionIds->map(fn (int $permissionId) => [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->delete();
        Cache::forget(Permission::CACHE_KEY);
    }
};
