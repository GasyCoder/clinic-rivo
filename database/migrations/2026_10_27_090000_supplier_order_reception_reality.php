<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-179 — ce qu'une livraison réelle fait subir à une commande.
 *
 *   purchase_orders       la confirmation du fournisseur, quand il en envoie
 *                         une : une trace (date, référence, document), jamais
 *                         un passage obligé — beaucoup de fournisseurs ne
 *                         confirment pas, et la commande doit rester
 *                         réceptionnable sans elle.
 *   purchase_order_lines  la rupture : un article commandé que le fournisseur
 *                         ne livrera pas. Son reliquat cesse d'être attendu,
 *                         ce qui permet enfin à la commande de se clore —
 *                         aujourd'hui une seule ligne jamais livrée la laisse
 *                         « Partiellement reçue » à vie.
 *   goods_receipt_lines   un article livré qui n'était pas commandé. La
 *                         commande n'est pas réécrite (ADR-098 : une commande
 *                         envoyée ne se modifie plus) ; la réception constate
 *                         ce qui est arrivé, la ligne ne pointe alors aucune
 *                         ligne de commande.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'purchase_orders.confirm' => 'Enregistrer la confirmation du fournisseur sur une commande (achats)',
    ];

    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->timestamp('supplier_confirmed_at')->nullable()->after('ordered_at');
            $table->string('supplier_confirmation_reference', 100)->nullable()->after('supplier_confirmed_at');
            $table->text('supplier_confirmation_notes')->nullable()->after('supplier_confirmation_reference');
            $table->string('supplier_confirmation_attachment_path')->nullable()->after('supplier_confirmation_notes');
            $table->string('supplier_confirmation_attachment_original_name')->nullable()->after('supplier_confirmation_attachment_path');
            $table->string('supplier_confirmation_attachment_mime_type')->nullable()->after('supplier_confirmation_attachment_original_name');
            $table->unsignedBigInteger('supplier_confirmation_attachment_size')->nullable()->after('supplier_confirmation_attachment_mime_type');
            $table->foreignId('supplier_confirmed_by')->nullable()->after('supplier_confirmation_attachment_size')
                ->constrained('users')->nullOnDelete();
            // ADR-098 — une commande suivie depuis le portail n'a pas d'auteur local.
            $table->uuid('external_supplier_confirmed_by_uuid')->nullable()->after('supplier_confirmed_by');
            $table->string('external_supplier_confirmed_by_name')->nullable()->after('external_supplier_confirmed_by_uuid');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->timestamp('shortage_at')->nullable()->after('quantity_received');
            $table->text('shortage_reason')->nullable()->after('shortage_at');
            $table->foreignId('shortage_by')->nullable()->after('shortage_reason')
                ->constrained('users')->nullOnDelete();
            $table->uuid('external_shortage_by_uuid')->nullable()->after('shortage_by');
            $table->string('external_shortage_by_name')->nullable()->after('external_shortage_by_uuid');
        });

        // Un article livré hors commande n'a aucune ligne de commande à
        // désigner. Les lignes déjà enregistrées en gardent une : rien n'est
        // réécrit, seule l'obligation tombe.
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')->nullable()->change();
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        // Comme tout l'approvisionnement (ADR-098) : accordée à aucun rôle du
        // site par défaut, et au Super Admin du portail (ADR-027). Suivre une
        // confirmation appartient à qui passe les commandes ; la *lire* suit
        // `purchase_orders.view`, déjà au socle PHARMACY (ADR-176).
        $superAdmin = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');

        if ($superAdmin && config('rivo.site.type') === 'admin') {
            DB::table('role_permissions')->insertOrIgnore(
                DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id')
                    ->map(fn (int $id) => ['role_id' => $superAdmin, 'permission_id' => $id, 'created_at' => $now, 'updated_at' => $now])
                    ->all(),
            );
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        // Une ligne hors commande ne pourrait plus être représentée : elle est
        // rattachée à rien, et la colonne redevient obligatoire.
        DB::table('goods_receipt_lines')->whereNull('purchase_order_line_id')->delete();

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')->nullable(false)->change();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shortage_by');
            $table->dropColumn(['shortage_at', 'shortage_reason', 'external_shortage_by_uuid', 'external_shortage_by_name']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_confirmed_by');
            $table->dropColumn([
                'supplier_confirmed_at', 'supplier_confirmation_reference', 'supplier_confirmation_notes',
                'supplier_confirmation_attachment_path', 'supplier_confirmation_attachment_original_name',
                'supplier_confirmation_attachment_mime_type', 'supplier_confirmation_attachment_size',
                'external_supplier_confirmed_by_uuid', 'external_supplier_confirmed_by_name',
            ]);
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
