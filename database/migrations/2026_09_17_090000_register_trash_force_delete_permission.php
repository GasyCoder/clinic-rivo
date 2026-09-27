<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La corbeille peut désormais supprimer définitivement — mais uniquement un
 * élément que rien n'a utilisé (ADR-010, même raisonnement que l'ADR-062
 * pour un compte n'ayant jamais servi). Le modèle lui-même refuse le reste.
 *
 * Réservée au SUPER_ADMIN du portail (ADR-027) ; un site en production
 * l'accorde nominativement depuis le portail plutôt qu'en rejouant un
 * seeder (ADR-064).
 */
return new class extends Migration
{
    private const NAME = 'trash.force_delete';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [[
                'name' => self::NAME,
                'label' => 'Supprimer définitivement un élément jamais utilisé de la corbeille',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['name'],
            ['label', 'updated_at'],
        );

        $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        $permissionId = DB::table('permissions')->where('name', self::NAME)->value('id');

        if ($roleId && $permissionId && config('rivo.site.type') === 'admin') {
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
        Cache::forget(Permission::CACHE_KEY);
    }
};
