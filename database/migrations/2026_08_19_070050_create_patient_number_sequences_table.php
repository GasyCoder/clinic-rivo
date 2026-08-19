<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs App\Services\Patient\PatientNumberGenerator. A single running
     * counter is enough — each site has its own database (ADR-001), so
     * there is only ever one sequence to track, not one per site.
     */
    public function up(): void
    {
        Schema::create('patient_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('next_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_number_sequences');
    }
};
