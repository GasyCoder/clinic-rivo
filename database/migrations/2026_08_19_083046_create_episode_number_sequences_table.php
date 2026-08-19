<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs App\Services\Episode\EpisodeNumberGenerator — same shape as
     * patient_number_sequences, one running counter per site database.
     */
    public function up(): void
    {
        Schema::create('episode_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('next_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_number_sequences');
    }
};
