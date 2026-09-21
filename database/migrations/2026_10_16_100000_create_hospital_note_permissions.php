<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-162 — la note quotidienne du séjour a ses propres droits.
 *
 * La lire est clinique : la Réception, qui voit le séjour (ADR-147), ne la lit
 * pas. L'écrire est un geste médical. Un site en production ne rejoue plus
 * `RolePermissionSeeder` (ADR-064), d'où cette migration.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'hospital_notes.view' => 'Lire les notes quotidiennes d’un séjour hospitalier',
        'hospital_notes.create' => 'Écrire la note quotidienne d’un séjour hospitalier (S/O/A/P)',
    ];

    /** @var array<string, list<string>> */
    private const GRANTS = [
        'MEDICINE' => ['hospital_notes.view', 'hospital_notes.create'],
        'NURSE' => ['hospital_notes.view'],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)
                ->map(fn (string $label, string $name): array => compact('name', 'label') + ['created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id', 'name');

        foreach (self::GRANTS as $code => $permissions) {
            $roleId = DB::table('roles')->where('code', $code)->whereNull('deleted_at')->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('role_permissions')->insertOrIgnore(
                collect($permissions)->map(fn (string $permission): array => [
                    'role_id' => $roleId,
                    'permission_id' => $ids[$permission],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Cache::forget(Permission::CACHE_KEY);
    }
};
