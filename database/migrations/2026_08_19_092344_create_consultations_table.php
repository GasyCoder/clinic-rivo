<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDC GitHub §15 lists `consultations.view/create/update/delete/
     * restore` (unlike prescriptions, which get `cancel` instead) — hence
     * SoftDeletable here, matching what the permission catalog implies.
     * No schema is given anywhere in the CDC beyond the permission names;
     * fields below are the client CDCF §6.1 médecin actions ("enregistrer
     * le motif de consultation", "enregistrer l'examen clinique",
     * "enregistrer la décision médicale finale").
     */
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->text('clinical_exam')->nullable();
            $table->string('decision')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('consulted_at');
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index('episode_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
