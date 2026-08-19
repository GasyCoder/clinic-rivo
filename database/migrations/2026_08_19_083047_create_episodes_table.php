<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema per CDC §21 (id/uuid/patient_id/episode_number/status/
     * medical_status/financial_status/administrative_status/started_at/
     * ended_at/created_by/timestamps). No deleted_at: §21 does not list one
     * for episodes (unlike patients, which does), and ADR-010 prefers
     * cancel/correct/reverse over deletion for this kind of critical
     * record — episodes are cancelled via status, never soft-deleted; see
     * App\Models\Episode::cancel().
     *
     * medical_status/financial_status are plain nullable strings, not
     * enums: they belong to modules not yet built (Médecine — Phase 2;
     * Facture/Caisse — later in Phase 1), confirmed with the team rather
     * than guessed here.
     */
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('episode_number')->unique();
            $table->string('status')->default('OPEN');
            $table->string('medical_status')->nullable();
            $table->string('financial_status')->nullable();
            $table->string('administrative_status')->default('PENDING_ORIENTATION');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
