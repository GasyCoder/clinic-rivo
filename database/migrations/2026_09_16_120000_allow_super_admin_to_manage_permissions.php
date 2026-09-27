<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Le catalogue des permissions devient administrable depuis le portail
 * (ADR-101) — création, libellé, retrait d'un nom jamais utilisé.
 *
 * `permissions.view` et `permissions.assign` existaient déjà : voir le
 * catalogue et en attribuer une ligne à un compte. Ces trois-là portent le
 * catalogue lui-même, et restent réservées au portail central (ADR-027).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'permissions.create' => 'Créer une permission au catalogue',
        'permissions.update' => 'Corriger le libellé d’une permission',
        'permissions.delete' => 'Retirer une permission jamais utilisée',
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
