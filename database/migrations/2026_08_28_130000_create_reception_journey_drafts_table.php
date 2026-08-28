<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_journey_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->unique()->constrained('episodes')->restrictOnDelete();
            $table->json('catalog_lines');
            $table->boolean('designation_deferred')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_journey_drafts');
    }
};
