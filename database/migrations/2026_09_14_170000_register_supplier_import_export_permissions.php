<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098 — supplier list import/export. Registered by migration, not only
 * in PermissionSeeder, so databases already initialized receive them too.
 * Granted to SUPER_ADMIN on the central portal only (ADR-027).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'medicine_suppliers.import' => 'Importer la liste des fournisseurs de médicaments',
        'medicine_suppliers.export' => 'Exporter la liste des fournisseurs de médicaments',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name): array => [
                'name' => $name,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($roleId && config('rivo.site.type') === 'admin') {
            DB::table('role_permissions')->insertOrIgnore(
                DB::table('permissions')
                    ->whereIn('name', array_keys(self::PERMISSIONS))
                    ->pluck('id')
                    ->map(fn (int $permissionId): array => [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
            );
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Cache::forget(Permission::CACHE_KEY);
    }
};
