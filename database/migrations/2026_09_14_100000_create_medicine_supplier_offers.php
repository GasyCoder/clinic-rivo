<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-097 — the versioned price a supplier currently quotes for a medicine
 * (spec §5/§6): "un médicament peut venir de plusieurs fournisseurs à des
 * prix différents simultanément". A dedicated table is required rather than
 * enriching the existing `medicine_supplier` pivot, because that pivot has a
 * composite primary key (medicine_id, medicine_supplier_id) and can
 * physically hold only one row per pair — it cannot carry price history.
 * Versioning here copies CatalogTariff/SetCatalogTariffAction exactly:
 * closing the current row (effective_until + active_key=null) before
 * opening a new one, never mutating an old quote in place. This is
 * strictly the fournisseur's *quoted* price — never to be confused with
 * CatalogTariff (the clinic's *selling* price to the patient) or with
 * pharmacy_stock_movements.unit_purchase_price (what was *actually paid*
 * on one specific stock entry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_supplier_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('medicine_supplier_id')->constrained('medicine_suppliers')->restrictOnDelete();
            $table->foreignId('supplier_catalog_item_id')->nullable()->constrained('supplier_catalog_items')->nullOnDelete();
            $table->string('supplier_reference', 120)->nullable();
            $table->decimal('quoted_price', 15, 2);
            $table->string('currency', 3)->default('MGA');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('active_key', 20)->nullable();
            $table->text('change_reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Explicit short name: the auto-generated one exceeds MySQL's
            // 64-char identifier limit.
            $table->unique(['medicine_id', 'medicine_supplier_id', 'active_key'], 'medicine_supplier_offer_active_unique');
            $table->index(['medicine_id', 'medicine_supplier_id', 'effective_from'], 'medicine_supplier_offer_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_supplier_offers');
    }
};
