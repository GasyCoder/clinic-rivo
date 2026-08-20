<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billable_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->string('source_module', 50);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->uuid('source_uuid')->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->char('currency', 3)->default('MGA');
            $table->boolean('payment_required_before_fulfillment')->default(false);
            $table->string('status')->default('PENDING');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['episode_id', 'status']);
            $table->index(['source_module', 'source_type']);
            $table->index(['source_type', 'source_id']);
            $table->index(['source_type', 'source_uuid']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('billable_item_id')
                ->nullable()
                ->unique()
                ->after('invoice_id')
                ->constrained('billable_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropUnique(['billable_item_id']);
            $table->dropConstrainedForeignId('billable_item_id');
        });

        Schema::dropIfExists('billable_items');
    }
};
