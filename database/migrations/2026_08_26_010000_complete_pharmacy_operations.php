<?php

use App\Enums\MedicineStockReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── medicine_categories ────────────────────────────────────────────────
        if (! Schema::hasTable('medicine_categories')) {
            Schema::create('medicine_categories', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('code', 60)->unique();
                $table->string('name')->index();
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletesWithReason();
            });
        }

        // ── medicine_suppliers ─────────────────────────────────────────────────
        if (! Schema::hasTable('medicine_suppliers')) {
            Schema::create('medicine_suppliers', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('code', 60)->unique();
                $table->string('name')->index();
                $table->string('contact_name')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletesWithReason();
            });
        }

        // ── medicines: add supplier / classification columns ───────────────────
        if (! Schema::hasColumn('medicines', 'medicine_category_id')) {
            Schema::table('medicines', function (Blueprint $table) {
                $table->foreignId('medicine_category_id')->nullable()->after('catalog_item_id')
                    ->constrained('medicine_categories')->nullOnDelete();
                $table->string('manufacturer')->nullable()->after('strength');
                $table->string('barcode', 100)->nullable()->unique()->after('manufacturer');
                $table->unsignedBigInteger('minimum_stock')->default(0)->after('barcode');
                $table->boolean('prescription_required')->default(true)->after('minimum_stock');
            });
        }

        // ── medicine_supplier (pivot) ──────────────────────────────────────────
        if (! Schema::hasTable('medicine_supplier')) {
            Schema::create('medicine_supplier', function (Blueprint $table) {
                $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
                $table->foreignId('medicine_supplier_id')->constrained('medicine_suppliers')->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['medicine_id', 'medicine_supplier_id']);
            });
        }

        // ── medicine_lots: add supplier link ───────────────────────────────────
        if (! Schema::hasColumn('medicine_lots', 'medicine_supplier_id')) {
            Schema::table('medicine_lots', function (Blueprint $table) {
                $table->foreignId('medicine_supplier_id')->nullable()->after('medicine_id')
                    ->constrained('medicine_suppliers')->nullOnDelete();
            });
        }

        // ── pharmacy_stock_movements: add supplier + purchase price ────────────
        if (! Schema::hasColumn('pharmacy_stock_movements', 'medicine_supplier_id')) {
            Schema::table('pharmacy_stock_movements', function (Blueprint $table) {
                $table->foreignId('medicine_supplier_id')->nullable()->after('medicine_lot_id')
                    ->constrained('medicine_suppliers')->nullOnDelete();
                $table->decimal('unit_purchase_price', 15, 2)->nullable()->after('balance_after');
            });
        }

        // ── medicine_stock_reservations: add remaining_quantity + backfill ─────
        if (! Schema::hasColumn('medicine_stock_reservations', 'remaining_quantity')) {
            Schema::table('medicine_stock_reservations', function (Blueprint $table) {
                $table->unsignedBigInteger('remaining_quantity')->default(0)->after('quantity');
            });

            DB::table('medicine_stock_reservations')
                ->where('status', MedicineStockReservationStatus::Reserved->value)
                ->update(['remaining_quantity' => DB::raw('quantity')]);
        }

        // ── billable_items: make episode_id nullable ───────────────────────────
        Schema::table('billable_items', function (Blueprint $table) {
            $table->foreignId('episode_id')->nullable()->change();
        });

        // ── invoices: make patient/episode nullable + add customer columns ─────
        if (! Schema::hasColumn('invoices', 'customer_type')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('patient_id')->nullable()->change();
                $table->foreignId('episode_id')->nullable()->change();
                $table->string('customer_type', 20)->default('PATIENT')->after('episode_id')->index();
                $table->string('customer_name')->nullable()->after('customer_type');
                $table->string('customer_phone', 50)->nullable()->after('customer_name');
                $table->string('source_module', 50)->nullable()->after('customer_phone')->index();
            });
        }

        // ── pharmacy_dispenses ─────────────────────────────────────────────────
        if (! Schema::hasTable('pharmacy_dispenses')) {
            Schema::create('pharmacy_dispenses', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('type', 20)->index();
                $table->foreignId('prescription_id')->nullable()->unique()->constrained('prescriptions')->restrictOnDelete();
                $table->foreignId('patient_id')->nullable()->constrained('patients')->restrictOnDelete();
                $table->foreignId('episode_id')->nullable()->constrained('episodes')->restrictOnDelete();
                $table->foreignId('invoice_id')->nullable()->unique()->constrained('invoices')->restrictOnDelete();
                $table->string('customer_name')->nullable();
                $table->string('customer_phone', 50)->nullable();
                $table->string('external_prescription_reference')->nullable();
                $table->string('external_prescriber')->nullable();
                $table->string('status', 30)->index();
                $table->timestamp('requested_at');
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();

                $table->index(['status', 'requested_at']);
            });
        }

        // ── pharmacy_dispense_lines ────────────────────────────────────────────
        if (! Schema::hasTable('pharmacy_dispense_lines')) {
            Schema::create('pharmacy_dispense_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pharmacy_dispense_id')->constrained('pharmacy_dispenses')->restrictOnDelete();
                $table->foreignId('prescription_line_id')->nullable()->unique()->constrained('prescription_lines')->restrictOnDelete();
                $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
                $table->foreignId('billable_item_id')->nullable()->unique()->constrained('billable_items')->restrictOnDelete();
                $table->string('medicine_name');
                $table->string('medicine_code', 60)->nullable();
                $table->string('unit', 50);
                $table->unsignedBigInteger('quantity_requested');
                $table->unsignedBigInteger('quantity_dispensed')->default(0);
                $table->timestamps();

                $table->index(['pharmacy_dispense_id', 'medicine_id']);
            });
        }

        // ── pharmacy_dispense_lot_reservations ─────────────────────────────────
        // FIX: the auto-generated FK name for `pharmacy_dispense_line_id` would be
        // `pharmacy_dispense_lot_reservations_pharmacy_dispense_line_id_foreign`
        // (68 chars), which exceeds MySQL's 64-char identifier limit.
        // We declare the column separately and use an explicit short constraint name.
        if (! Schema::hasTable('pharmacy_dispense_lot_reservations')) {
            Schema::create('pharmacy_dispense_lot_reservations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();

                // Column only — FK added below with an explicit short name
                $table->unsignedBigInteger('pharmacy_dispense_line_id');

                $table->foreignId('medicine_lot_id')->constrained('medicine_lots')->restrictOnDelete();
                $table->unsignedBigInteger('quantity');
                $table->unsignedBigInteger('remaining_quantity');
                $table->string('status', 20)->default('RESERVED')->index();
                $table->timestamp('reserved_at');
                $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('released_at')->nullable();
                $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('release_reason')->nullable();
                $table->timestamp('dispensed_at')->nullable();
                $table->foreignId('dispensed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // Explicit short name (auto-generated name = 68 chars > MySQL 64-char limit)
                $table->foreign('pharmacy_dispense_line_id', 'pdlr_dispense_line_fk')
                    ->references('id')
                    ->on('pharmacy_dispense_lines')
                    ->restrictOnDelete();

                $table->unique(['pharmacy_dispense_line_id', 'medicine_lot_id'], 'pharmacy_dispense_line_lot_unique');
                $table->index(['medicine_lot_id', 'status']);
            });
        }

        // ── pharmacy_dispense_events ───────────────────────────────────────────
        if (! Schema::hasTable('pharmacy_dispense_events')) {
            Schema::create('pharmacy_dispense_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('pharmacy_dispense_id')->constrained('pharmacy_dispenses')->restrictOnDelete();
                $table->string('delivery_number')->unique();
                $table->text('notes')->nullable();
                $table->timestamp('dispensed_at');
                $table->foreignId('dispensed_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['pharmacy_dispense_id', 'dispensed_at']);
            });
        }

        // ── pharmacy_dispense_allocations ──────────────────────────────────────
        if (! Schema::hasTable('pharmacy_dispense_allocations')) {
            Schema::create('pharmacy_dispense_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pharmacy_dispense_event_id')->constrained('pharmacy_dispense_events')->restrictOnDelete();
                $table->foreignId('pharmacy_dispense_line_id')->constrained('pharmacy_dispense_lines')->restrictOnDelete();
                $table->foreignId('medicine_lot_id')->constrained('medicine_lots')->restrictOnDelete();
                $table->foreignId('pharmacy_stock_movement_id')->unique()->constrained('pharmacy_stock_movements')->restrictOnDelete();
                $table->unsignedBigInteger('quantity');
                $table->timestamps();

                $table->index(['pharmacy_dispense_line_id', 'medicine_lot_id'], 'pharmacy_allocation_line_lot_index');
            });
        }

        // ── pharmacy_stock_alerts ──────────────────────────────────────────────
        if (! Schema::hasTable('pharmacy_stock_alerts')) {
            Schema::create('pharmacy_stock_alerts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
                $table->string('type', 30)->index();
                $table->string('status', 20)->default('OPEN')->index();
                $table->string('active_key', 20)->nullable();
                $table->unsignedBigInteger('available_quantity');
                $table->unsignedBigInteger('threshold');
                $table->timestamp('triggered_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(['medicine_id', 'active_key']);
                $table->index(['status', 'triggered_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_stock_alerts');
        Schema::dropIfExists('pharmacy_dispense_allocations');
        Schema::dropIfExists('pharmacy_dispense_events');
        Schema::dropIfExists('pharmacy_dispense_lot_reservations');
        Schema::dropIfExists('pharmacy_dispense_lines');
        Schema::dropIfExists('pharmacy_dispenses');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['customer_type']);
            $table->dropIndex(['source_module']);
            $table->dropColumn(['customer_type', 'customer_name', 'customer_phone', 'source_module']);
        });

        if (DB::table('invoices')->whereNull('patient_id')->doesntExist()) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('patient_id')->nullable(false)->change();
            });
        }

        if (DB::table('invoices')->whereNull('episode_id')->doesntExist()) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('episode_id')->nullable(false)->change();
            });
        }

        if (DB::table('billable_items')->whereNull('episode_id')->doesntExist()) {
            Schema::table('billable_items', function (Blueprint $table) {
                $table->foreignId('episode_id')->nullable(false)->change();
            });
        }

        Schema::table('medicine_stock_reservations', function (Blueprint $table) {
            $table->dropColumn('remaining_quantity');
        });

        Schema::table('pharmacy_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medicine_supplier_id');
            $table->dropColumn('unit_purchase_price');
        });

        Schema::table('medicine_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medicine_supplier_id');
        });

        Schema::dropIfExists('medicine_supplier');

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medicine_category_id');
            $table->dropUnique(['barcode']);
            $table->dropColumn(['manufacturer', 'barcode', 'minimum_stock', 'prescription_required']);
        });

        Schema::dropIfExists('medicine_suppliers');
        Schema::dropIfExists('medicine_categories');
    }
};
