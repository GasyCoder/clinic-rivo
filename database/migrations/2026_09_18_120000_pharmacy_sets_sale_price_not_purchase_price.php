<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-170 — la Pharmacie fixe le prix de vente de ses médicaments, et ne
 * voit plus le prix d'achat, confidentiel.
 *
 * RolePermissionSeeder ne doit pas être rejoué sur un site en production
 * (ADR-064) : cette migration applique au seul rôle PHARMACY exactement ce
 * changement, sans toucher aux exceptions individuelles d'un compte.
 */
return new class extends Migration
{
    private const GRANTED = 'medicines.sale_price.update';

    private const REVOKED = ['stock.cost.view', 'stock.cost.record'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [[
                'name' => self::GRANTED,
                'label' => 'Fixer et modifier le prix de vente d’un médicament',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionId = DB::table('permissions')->where('name', self::GRANTED)->value('id');

        // Le portail détient tout le catalogue de permissions (ADR-025).
        $superAdmin = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($superAdmin && config('rivo.site.type') === 'admin') {
            DB::table('role_permissions')->insertOrIgnore([[
                'role_id' => $superAdmin, 'permission_id' => $permissionId,
                'created_at' => $now, 'updated_at' => $now,
            ]]);
        }

        $pharmacy = DB::table('roles')->where('code', 'PHARMACY')->value('id');

        if ($pharmacy && config('rivo.site.type') !== 'admin') {
            DB::table('role_permissions')->insertOrIgnore([[
                'role_id' => $pharmacy, 'permission_id' => $permissionId,
                'created_at' => $now, 'updated_at' => $now,
            ]]);

            DB::table('role_permissions')
                ->where('role_id', $pharmacy)
                ->whereIn('permission_id', DB::table('permissions')->whereIn('name', self::REVOKED)->pluck('id'))
                ->delete();
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $pharmacy = DB::table('roles')->where('code', 'PHARMACY')->value('id');

        if ($pharmacy) {
            $now = now();

            DB::table('role_permissions')->insertOrIgnore(
                DB::table('permissions')->whereIn('name', self::REVOKED)->pluck('id')
                    ->map(fn (int $id) => ['role_id' => $pharmacy, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now])
                    ->all(),
            );

            DB::table('role_permissions')
                ->where('role_id', $pharmacy)
                ->where('permission_id', DB::table('permissions')->where('name', self::GRANTED)->value('id'))
                ->delete();
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
