<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `medication_name` is free text, not a foreign key to a medicines
     * catalog — Pharmacie (Phase 4) doesn't exist yet, so nothing to link
     * to. Reconciling prescription lines with a real medicine catalog is
     * that future module's job, not invented here.
     */
    public function up(): void
    {
        Schema::create('prescription_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->string('medication_name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();

            $table->index('prescription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_lines');
    }
};
