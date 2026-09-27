<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-192 (amendement du 2026-09-25) — supprimer définitivement un coupon archivé
 * qui n'a jamais servi. Accordé à aucun rôle d'un site : le Super Administrateur
 * du portail le reçoit par la synchronisation de l'ADR-186.
 */
return new class extends Migration
{
    private const NAME = 'discount_coupons.force_delete';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [['name' => self::NAME, 'label' => 'Supprimer définitivement un coupon archivé jamais utilisé', 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['label', 'updated_at'],
        );

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::NAME)->value('id');

        if ($id !== null) {
            DB::table('user_permissions')->where('permission_id', $id)->delete();
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
