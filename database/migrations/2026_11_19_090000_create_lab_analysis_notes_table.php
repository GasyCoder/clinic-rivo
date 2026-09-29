<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-218 — la note d'une ligne d'analyse (la « conclusion partielle » du
 * laboratoire de la clinique, labo-vuejs `analyse_conclusion_notes`) : sous
 * « Plaquettes », « Microcytose isolée avec polynucléose neutrophile ».
 *
 * Une note par ligne du catalogue et par analyse demandée, groupes compris.
 * C'est un brouillon tant que l'analyse n'est pas envoyée au médecin ; elle ne
 * se modifie plus ensuite, comme le résultat qu'elle accompagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_analysis_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lab_request_item_id')->constrained('lab_request_items')->restrictOnDelete();
            $table->foreignId('analysis_catalog_id')->constrained('analysis_catalogs')->restrictOnDelete();
            $table->text('note');
            $table->foreignId('written_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['lab_request_item_id', 'analysis_catalog_id'], 'lab_analysis_notes_item_analysis_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_analysis_notes');
    }
};
