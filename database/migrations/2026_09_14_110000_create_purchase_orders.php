<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-097 — spec §8: a purchase order (commande fournisseur) with a status
 * workflow (Draft → Ordered → PartiallyReceived/Received, or Cancelled).
 * Each line snapshots its unit_price at order time — never recomputed
 * later, matching the snapshot discipline used everywhere else in this app
 * (ADR-024/031/047). quantity_received is denormalized here for fast status
 * recalculation; the authoritative receipt detail lives in
 * goods_receipt_lines (next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number')->unique();
            $table->foreignId('medicine_supplier_id')->constrained('medicine_suppliers')->restrictOnDelete();
            $table->string('status', 30)->default('DRAFT')->index();
            $table->timestamp('ordered_at')->nullable();
            $table->date('expected_delivery_at')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('MGA');
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['medicine_supplier_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('medicine_supplier_offer_id')->nullable()->constrained('medicine_supplier_offers')->nullOnDelete();
            $table->unsignedInteger('quantity_ordered');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
