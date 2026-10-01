<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-216, amendement du 2026-09-29 — le médecin qui a reçu un résultat peut
 * demander qu'il soit refait (« Demander à refaire » sur sa feuille de résultats).
 * Accordé au socle MEDICINE ; le Super Administrateur le retire depuis
 * « Rôles & permissions » s'il le décide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = DB::table('permissions')->where('name', 'laboratory_results.return')->value('id');
        $role = DB::table('roles')->where('code', 'MEDICINE')->whereNull('deleted_at')->value('id');

        if ($permission !== null && $role !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('name', 'laboratory_results.return')->value('id');
        $role = DB::table('roles')->where('code', 'MEDICINE')->value('id');

        if ($permission !== null && $role !== null) {
            DB::table('role_permissions')->where(['role_id' => $role, 'permission_id' => $permission])->delete();
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
