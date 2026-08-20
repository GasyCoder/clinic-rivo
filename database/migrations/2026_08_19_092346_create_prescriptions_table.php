<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC §19's inter-site transfer payload explicitly lists "prescriptions
     * utiles" as exchanged data — hence `uuid` here (ADR-005), unlike
     * Consultation/Diagnosis which aren't named there. CDC GitHub §15 lists
     * `prescriptions.cancel` (not delete) — status-based, mirrors
     * EpisodeStatus/Episode::cancel() rather than SoftDeletable.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->string('status')->default('ACTIVE');
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('consultation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
