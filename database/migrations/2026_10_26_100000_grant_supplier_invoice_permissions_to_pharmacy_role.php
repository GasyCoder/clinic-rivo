<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-176 — la facture du fournisseur est la seconde étape de la réception
 * (ADR-175) : elle arrive dans le carton, avec la marchandise. Laisser la
 * réception au rôle PHARMACY sans elle arrêtait l'assistant à mi-chemin, et
 * chaque livraison restait « facture en attente » sans que personne sur place
 * puisse la saisir.
 *
 * Deux droits, et eux seuls : voir les factures du fournisseur et en
 * enregistrer une. Les corriger, les mettre à la corbeille et les restaurer
 * restent accordés nominativement (ADR-098) — revenir sur une pièce
 * comptable déjà enregistrée n'est pas la même autorité que la saisir.
 *
 * Conformément à l'ADR-064, un site déjà en production reçoit ces droits par
 * cette migration, jamais en rejouant RolePermissionSeeder.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'supplier_invoices.view',
        'supplier_invoices.create',
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
