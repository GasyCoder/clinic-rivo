<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-108 — la feuille proposée d'office pour un examen d'imagerie.
 *
 * Ouvrir « Saisir le résultat » d'une échographie pré-applique la feuille qui
 * lui correspond. Ce lien est **réglé, jamais déduit** du nom ou du code de
 * l'examen (ADR-052) — même principe que la famille d'imagerie (ADR-106). Il
 * est propre à chaque site et ne modifie pas le catalogue, que seul le Super
 * Admin paramètre (ADR-024).
 *
 * `template_key` nul veut dire « aucune feuille d'office » : un choix
 * explicite, qui l'emporte sur le réglage par défaut du code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imaging_exam_report_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->restrictOnDelete();
            $table->string('template_key', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imaging_exam_report_templates');
    }
};
