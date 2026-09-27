<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-174 — la Pharmacie peut vendre un produit sous un autre nom que celui
 * du fournisseur. Accordée au rôle PHARMACY sans rejouer le seeder (ADR-064).
 */
return new class extends Migration
{
    private const NAME = 'medicines.name.update';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [['name' => self::NAME, 'label' => 'Renommer un médicament sous son nom de vente à la pharmacie', 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionId = DB::table('permissions')->where('name', self::NAME)->value('id');
        $role = config('rivo.site.type') === 'admin' ? 'SUPER_ADMIN' : 'PHARMACY';
        $roleId = DB::table('roles')->where('code', $role)->value('id');

        if ($roleId) {
            DB::table('role_permissions')->insertOrIgnore([[
                'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
            ]]);
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission_id', DB::table('permissions')->where('name', self::NAME)->value('id'))
            ->delete();

        Cache::forget(Permission::CACHE_KEY);
    }
};
