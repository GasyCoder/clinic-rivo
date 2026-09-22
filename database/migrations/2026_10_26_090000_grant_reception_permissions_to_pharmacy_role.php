<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-176 — réceptionner la marchandise redevient le travail du rôle
 * PHARMACY. L'ADR-098 avait retiré toute la chaîne d'approvisionnement de son
 * socle pour que le Super Admin l'accorde nominativement ; en pratique, aucune
 * pharmacie ne pouvait réceptionner sa propre livraison, et l'écran des
 * commandes affichait « Envoyée au fournisseur » comme s'il n'y avait plus
 * rien à faire.
 *
 * Trois droits, et eux seuls : voir la commande à réceptionner, voir les
 * réceptions, en enregistrer une. Commander, facturer, gérer les fournisseurs
 * et leurs catalogues restent accordés nominativement (ADR-098) — décider un
 * achat n'est pas recevoir un carton.
 *
 * Conformément à l'ADR-064, un site déjà en production reçoit ces droits par
 * cette migration, jamais en rejouant RolePermissionSeeder.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'purchase_orders.view',
        'goods_receipts.view',
        'goods_receipts.create',
    ];

    public function up(): void
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

    public function down(): void
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
};
