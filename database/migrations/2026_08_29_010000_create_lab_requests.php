<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->foreignId('source_orientation_id')->constrained('episode_orientations')->restrictOnDelete();
            $table->foreignId('lab_orientation_id')->constrained('episode_orientations')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('requested_at');
            $table->timestamps();
        });

        Schema::create('lab_request_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lab_request_id')->constrained('lab_requests')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->string('catalog_item_code_snapshot', 60);
            $table->string('catalog_item_name_snapshot');
            $table->text('result_value')->nullable();
            $table->text('result_notes')->nullable();
            $table->timestamp('resulted_at')->nullable();
            $table->foreignId('resulted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_request_items');
        Schema::dropIfExists('lab_requests');
    }
};
