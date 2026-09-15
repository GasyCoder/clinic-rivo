<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-097/098 — the procurement permissions were only listed in
 * PermissionSeeder, so a site or portal initialized before them never
 * received them: the gate then refused every supplier screen, even to the
 * Super Admin. This registers them on existing databases.
 *
 * Only the central portal grants them to SUPER_ADMIN, exactly like
 * RolePermissionSeeder: on a clinic site SUPER_ADMIN holds no permission
 * (ADR-027) and no operational role receives them by default (ADR-098).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'supplier_catalogs.view' => 'Voir les catalogues fournisseurs',
        'supplier_catalogs.create' => 'Importer un catalogue fournisseur',
        'supplier_catalogs.update' => 'Activer un catalogue fournisseur',
        'supplier_catalogs.delete' => 'Archiver un catalogue fournisseur',
        'supplier_catalogs.restore' => 'Restaurer un catalogue fournisseur',
        'medicine_supplier_offers.view' => 'Voir les prix proposés par les fournisseurs',
        'medicine_supplier_offers.create' => 'Enregistrer un premier prix fournisseur',
        'medicine_supplier_offers.update' => 'Réviser un prix fournisseur',
        'purchase_orders.view' => 'Voir les commandes fournisseurs',
        'purchase_orders.create' => 'Créer une commande fournisseur',
        'purchase_orders.update' => 'Modifier une commande fournisseur en brouillon',
        'purchase_orders.submit' => 'Passer une commande fournisseur',
        'purchase_orders.cancel' => 'Annuler une commande fournisseur',
        'goods_receipts.view' => 'Voir les réceptions de commandes',
        'goods_receipts.create' => 'Réceptionner une commande fournisseur',
        'supplier_invoices.view' => 'Voir les factures fournisseurs',
        'supplier_invoices.create' => 'Enregistrer une facture fournisseur',
        'supplier_invoices.delete' => 'Archiver une facture fournisseur',
        'supplier_invoices.restore' => 'Restaurer une facture fournisseur',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name): array => [
                'name' => $name,
                'label' => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $roleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($roleId && config('rivo.site.type') === 'admin') {
            DB::table('role_permissions')->insertOrIgnore(
                DB::table('permissions')
                    ->whereIn('name', array_keys(self::PERMISSIONS))
                    ->pluck('id')
                    ->map(fn (int $permissionId): array => [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
            );
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        // The permissions stay: PermissionSeeder lists them, and deleting
        // them here would silently strip grants made since from the portal.
        Cache::forget(Permission::CACHE_KEY);
    }
};
