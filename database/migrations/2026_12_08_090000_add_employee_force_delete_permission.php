<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-236 — supprimer définitivement un dossier employé archivé qui n'a servi nulle part
 * (doublon, saisie à tort). Accordé à aucun rôle d'un site : le Super Admin le reçoit à la
 * migration du portail (ADR-186) et l'accorde nominativement s'il le décide.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'employees.force_delete' => 'Supprimer définitivement un dossier employé archivé qui n’a servi nulle part (doublon, saisie à tort)',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
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
