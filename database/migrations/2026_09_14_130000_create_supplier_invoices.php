<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-097 — spec §10: a supplier invoice is a purely administrative
 * bookkeeping record (number, date, amount, optional scanned attachment,
 * links to the order/receipt it settles). It never touches stock, lots or
 * movements — that impact is already fully captured by the stock entries
 * created during reception (goods_receipt_lines). An invoice can therefore
 * never become a second, conflicting source of stock truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number', 100);
            $table->foreignId('medicine_supplier_id')->constrained('medicine_suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $table->date('invoice_date');
            $table->decimal('total_amount', 15, 2);
            $table->string('currency', 3)->default('MGA');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime_type')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->unique(['medicine_supplier_id', 'invoice_number']);
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
    }
};
