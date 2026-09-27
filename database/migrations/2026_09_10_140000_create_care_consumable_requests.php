<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-072 — Soins declares the consumables it actually used on the patient
 * and Pharmacy records the stock exit. Deliberately separate from
 * pharmacy_dispenses: a dispensation may not leave the shelf before its
 * invoice is settled (ADR-049), a consumable already applied to a wound
 * has no such option.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_consumable_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('request_number', 40)->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            // The visit this request belongs to. Nullable so a request stays
            // readable if the orientation row is ever reworked; the episode
            // is the load-bearing link.
            $table->foreignId('care_orientation_id')->nullable()
                ->constrained('episode_orientations')->nullOnDelete();
            $table->foreignId('care_record_id')->nullable()
                ->constrained('care_records')->nullOnDelete();
            $table->string('status', 30)->index();
            $table->text('notes')->nullable();
            $table->timestamp('requested_at');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('served_at')->nullable();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index(['episode_id', 'status']);
        });

        Schema::create('care_consumable_request_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('care_consumable_request_id')
                ->constrained('care_consumable_requests')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('billable_item_id')->nullable()->unique()
                ->constrained('billable_items')->restrictOnDelete();
            // Snapshots: a later catalog correction never rewrites what the
            // nurse declared having used (ADR-024).
            $table->string('medicine_name');
            $table->string('medicine_code', 60)->nullable();
            $table->string('unit', 50);
            $table->unsignedBigInteger('quantity_requested');
            $table->unsignedBigInteger('quantity_served')->default(0);
            $table->timestamps();

            // Explicit short name: the auto-generated one would exceed
            // MySQL's 64-char identifier limit.
            $table->index(['care_consumable_request_id', 'medicine_id'], 'ccrl_request_medicine_idx');
        });

        // Which lot served which line, and through which immutable movement.
        // FK names are declared explicitly: the auto-generated name for
        // care_consumable_request_line_id would exceed MySQL's 64-char limit.
        Schema::create('care_consumable_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('care_consumable_request_line_id');
            $table->foreignId('medicine_lot_id')->constrained('medicine_lots')->restrictOnDelete();
            $table->unsignedBigInteger('pharmacy_stock_movement_id');
            $table->unsignedBigInteger('quantity');
            $table->timestamp('served_at');
            $table->foreignId('served_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('care_consumable_request_line_id', 'cca_line_fk')
                ->references('id')->on('care_consumable_request_lines')->restrictOnDelete();
            $table->foreign('pharmacy_stock_movement_id', 'cca_movement_fk')
                ->references('id')->on('pharmacy_stock_movements')->restrictOnDelete();
            $table->index('care_consumable_request_line_id', 'cca_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_consumable_allocations');
        Schema::dropIfExists('care_consumable_request_lines');
        Schema::dropIfExists('care_consumable_requests');
    }
};
