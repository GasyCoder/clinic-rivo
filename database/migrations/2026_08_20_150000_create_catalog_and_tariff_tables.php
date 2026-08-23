<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('type', 30)->index();
            $table->string('module', 40)->index();
            $table->string('unit', 50);
            $table->boolean('billable')->index();
            $table->boolean('stockable')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['module', 'type', 'deleted_at']);
        });

        Schema::create('catalog_tariffs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('MGA');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('active_key', 20)->nullable();
            $table->text('change_reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['catalog_item_id', 'active_key']);
            $table->index(['catalog_item_id', 'effective_from']);
            $table->index(['catalog_item_id', 'effective_until']);
        });

        Schema::table('billable_items', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')
                ->nullable()
                ->after('source_uuid')
                ->constrained('catalog_items')
                ->restrictOnDelete();
            $table->foreignId('catalog_tariff_id')
                ->nullable()
                ->after('catalog_item_id')
                ->constrained('catalog_tariffs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billable_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_tariff_id');
            $table->dropConstrainedForeignId('catalog_item_id');
        });

        Schema::dropIfExists('catalog_tariffs');
        Schema::dropIfExists('catalog_items');
    }
};
