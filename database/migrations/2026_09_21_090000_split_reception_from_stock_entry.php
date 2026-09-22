<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-111 — la réception constate la livraison, l'entrée en stock la fait
 * entrer au stock : deux gestes, deux moments.
 *
 *   goods_receipt_lines   uuid (jamais d'identifiant SQL à l'écran, ADR-050),
 *                         remarque de réception, et qui/quand l'a entrée en
 *                         stock. Les lignes déjà reçues avant cette décision
 *                         ont déjà leur mouvement : elles sont marquées
 *                         entrées à leur date, pour ne jamais être proposées
 *                         une seconde fois.
 *   supplier_invoices     date d'échéance (facultative).
 *   purchase_orders       corbeille, pour un brouillon jamais envoyé.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'purchase_orders.delete' => 'Mettre à la corbeille une commande fournisseur en brouillon',
        'purchase_orders.restore' => 'Restaurer une commande fournisseur mise à la corbeille',
    ];

    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->text('notes')->nullable()->after('unit_purchase_price');
            $table->timestamp('stocked_at')->nullable()->after('pharmacy_stock_movement_id');
            $table->foreignId('stocked_by')->nullable()->after('stocked_at')->constrained('users')->nullOnDelete();
        });

        DB::table('goods_receipt_lines')->orderBy('id')->each(function (object $line): void {
            DB::table('goods_receipt_lines')->where('id', $line->id)->update([
                'uuid' => (string) Str::uuid(),
                'stocked_at' => $line->pharmacy_stock_movement_id ? $line->created_at : null,
            ]);
        });

        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('invoice_date');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->softDeletesWithReason();
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => [
                'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        // Comme tout l'approvisionnement (ADR-098) : accordées à aucun rôle
        // du site par défaut, et au Super Admin du portail (ADR-027).
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
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['deleted_at', 'delete_reason']);
        });

        Schema::table('supplier_invoices', function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stocked_by');
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'notes', 'stocked_at']);
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
