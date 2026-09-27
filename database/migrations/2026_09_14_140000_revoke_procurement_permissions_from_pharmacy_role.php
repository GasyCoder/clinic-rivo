<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098 — suppliers and the procurement chain are granted to no role by
 * default. RolePermissionSeeder no longer lists them for PHARMACY, but a
 * database already seeded under ADR-097 still holds those grants: this
 * removes exactly them from the PHARMACY role, and nothing else. Individual
 * ALLOW exceptions given to named accounts are left untouched.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'medicine_suppliers.view',
        'supplier_catalogs.view', 'supplier_catalogs.create', 'supplier_catalogs.update',
        'supplier_catalogs.delete', 'supplier_catalogs.restore',
        'medicine_supplier_offers.view', 'medicine_supplier_offers.create', 'medicine_supplier_offers.update',
        'purchase_orders.view', 'purchase_orders.create', 'purchase_orders.update',
        'purchase_orders.submit', 'purchase_orders.cancel',
        'goods_receipts.view', 'goods_receipts.create',
        'supplier_invoices.view', 'supplier_invoices.create',
        'supplier_invoices.delete', 'supplier_invoices.restore',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', 'PHARMACY')->value('id');

        if (! $roleId) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id'))
            ->delete();

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('code', 'PHARMACY')->value('id');

        if (! $roleId) {
            return;
        }

        $now = now();

        DB::table('role_permissions')->insertOrIgnore(
            DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id')
                ->map(fn (int $permissionId) => [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );

        Cache::forget(Permission::CACHE_KEY);
    }
};
