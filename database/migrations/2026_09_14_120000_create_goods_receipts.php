<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-097 — spec §9: receiving a purchase order is a distinct step from
 * ordering it, and can be partial (ordered 100, received 80 → only 80 enter
 * stock). Each goods_receipt_line links back to the purchase_order_line it
 * fulfils and, once processed, to the exact pharmacy_stock_movement that
 * RecordStockEntryAction (already existing, unchanged) created for it —
 * this is the sole integration point between procurement and the existing,
 * immutable stock-movement ledger. pharmacy_stock_movements itself is never
 * modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('receipt_number')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'received_at']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('expires_at');
            $table->unsignedInteger('quantity_received');
            $table->decimal('unit_purchase_price', 15, 2)->nullable();
            $table->foreignId('pharmacy_stock_movement_id')->nullable()->unique()
                ->constrained('pharmacy_stock_movements')->nullOnDelete();
            $table->timestamps();

            // Explicit short name: the auto-generated one exceeds MySQL's
            // 64-char identifier limit.
            $table->index(['goods_receipt_id', 'purchase_order_line_id'], 'goods_receipt_line_po_line_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
