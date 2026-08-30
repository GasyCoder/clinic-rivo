<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'users.force_delete' => 'Supprimer définitivement un compte jamais utilisé (ADR-062)',
    ];

    public function up(): void
    {
        $now = now();
        $rows = collect(self::PERMISSIONS)->map(
            fn (string $label, string $name): array => compact('name', 'label') + [
                'created_at' => $now,
                'updated_at' => $now,
            ],
        )->values()->all();

        DB::table('permissions')->upsert($rows, ['name'], ['label', 'updated_at']);

        // On an existing site or portal the role is already present when the
        // migration runs. A fresh installation receives the same grant later
        // from RolePermissionSeeder, which grants every permission to
        // SUPER_ADMIN.
        $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($roleId) {
            $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
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
