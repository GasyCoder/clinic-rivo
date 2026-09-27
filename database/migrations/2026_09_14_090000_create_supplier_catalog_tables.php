<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-097 — "Drive style" catalog files per supplier (spec §2/§3): a
 * supplier can hold many catalog files (Excel or PDF), at most one marked
 * active at a time. Excel catalogs can be parsed into supplier_catalog_items
 * — raw, unlinked rows, deliberately NOT the clinic stock/catalog (spec §3).
 * A row becomes part of the clinic catalog only once explicitly linked to a
 * Medicine (linked_medicine_id), which then feeds a versioned
 * medicine_supplier_offers row (see the next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalogs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('medicine_supplier_id')->constrained('medicine_suppliers')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('kind', 10)->index();
            $table->date('catalog_date')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->string('active_key', 20)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->unique(['medicine_supplier_id', 'active_key']);
            $table->index(['medicine_supplier_id', 'created_at']);
        });

        Schema::create('supplier_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_catalog_id')->constrained('supplier_catalogs')->cascadeOnDelete();
            $table->string('reference', 120)->nullable();
            $table->string('medicine_label');
            $table->string('presentation')->nullable();
            $table->decimal('supplier_price', 15, 2);
            $table->unsignedInteger('row_number');
            $table->foreignId('linked_medicine_id')->nullable()->constrained('medicines')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['supplier_catalog_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_items');
        Schema::dropIfExists('supplier_catalogs');
    }
};
