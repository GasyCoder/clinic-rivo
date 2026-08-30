<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION_NAME = 'cash_registers.release';

    private const PERMISSION_LABEL = 'Détacher à distance le titulaire d’une session ouverte';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert([[
            'name' => self::PERMISSION_NAME,
            'label' => self::PERMISSION_LABEL,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['name'], ['label', 'updated_at']);

        // On an existing central portal the role is already present when the
        // migration runs. A fresh installation receives the same grant later
        // from RolePermissionSeeder, which grants every portal permission to
        // SUPER_ADMIN.
        $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($roleId) {
            $permissionId = DB::table('permissions')->where('name', self::PERMISSION_NAME)->value('id');

            DB::table('role_permissions')->insertOrIgnore([[
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', self::PERMISSION_NAME)->delete();
        Cache::forget(Permission::CACHE_KEY);
    }
};
