<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->boolean('reception_selectable')
                ->default(false)
                ->after('stockable')
                ->index();
            $table->string('reception_routing_mode', 40)
                ->nullable()
                ->after('reception_selectable')
                ->index();
        });

        Schema::table('episodes', function (Blueprint $table) {
            $table->boolean('designation_deferred')
                ->default(false)
                ->after('administrative_status');
            $table->timestamp('service_plan_finalized_at')
                ->nullable()
                ->after('designation_deferred');
        });

        Schema::create('episode_service_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->uuid('catalog_item_uuid');
            $table->string('catalog_code', 60);
            $table->string('designation');
            $table->string('module', 40);
            $table->string('routing_mode', 40);
            $table->string('unit', 50);
            $table->decimal('unit_price', 15, 2);
            $table->string('currency', 3)->default('MGA');
            $table->decimal('quantity', 12, 2);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // Reception submits at most one line per designation; quantity
            // carries repetitions. This also makes a retry idempotent.
            $table->unique(['episode_id', 'catalog_item_id'], 'episode_service_request_item_unique');
            $table->index(['episode_id', 'routing_mode'], 'episode_service_request_route');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_service_requests');

        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['designation_deferred', 'service_plan_finalized_at']);
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['reception_selectable', 'reception_routing_mode']);
        });
    }
};
