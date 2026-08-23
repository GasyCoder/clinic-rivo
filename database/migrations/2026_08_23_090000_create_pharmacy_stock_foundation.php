<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->restrictOnDelete();
            $table->string('generic_name')->nullable()->index();
            $table->string('form', 50)->index();
            $table->string('strength')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('medicine_lots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('received_at')->nullable();
            $table->date('expires_at')->index();
            $table->unsignedBigInteger('quantity_on_hand')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['medicine_id', 'lot_number']);
            $table->index(['medicine_id', 'active', 'expires_at']);
        });

        Schema::table('prescription_lines', function (Blueprint $table) {
            $table->foreignId('medicine_id')
                ->nullable()
                ->after('prescription_id')
                ->constrained('medicines')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1)->after('medication_name');
            $table->unsignedBigInteger('stock_available_at_prescription')->nullable()->after('quantity');
            $table->date('earliest_expiration_at')->nullable()->after('stock_available_at_prescription');
        });

        Schema::create('pharmacy_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('medicine_lot_id')->constrained('medicine_lots')->restrictOnDelete();
            $table->string('type', 30)->index();
            $table->bigInteger('quantity_delta');
            $table->unsignedBigInteger('balance_after');
            $table->string('source_key')->nullable()->unique();
            $table->text('reason');
            $table->timestamp('occurred_at')->index();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['medicine_lot_id', 'occurred_at']);
        });

        Schema::create('medicine_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('prescription_line_id')->constrained('prescription_lines')->restrictOnDelete();
            $table->foreignId('medicine_lot_id')->constrained('medicine_lots')->restrictOnDelete();
            $table->unsignedBigInteger('quantity');
            $table->string('status', 20)->default('RESERVED')->index();
            $table->timestamp('reserved_at');
            $table->foreignId('reserved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestamp('dispensed_at')->nullable();
            $table->foreignId('dispensed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['prescription_line_id', 'medicine_lot_id'], 'medicine_reservation_line_lot_unique');
            $table->index(['medicine_lot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_stock_reservations');
        Schema::dropIfExists('pharmacy_stock_movements');

        Schema::table('prescription_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medicine_id');
            $table->dropColumn([
                'quantity',
                'stock_available_at_prescription',
                'earliest_expiration_at',
            ]);
        });

        Schema::dropIfExists('medicine_lots');
        Schema::dropIfExists('medicines');
    }
};
