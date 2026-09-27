<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saisie en cours du dossier Maternité, conservée côté serveur pour qu'une
 * actualisation ne la fasse jamais disparaître — la protection que la fiche de
 * soins (ADR-073) et la consultation Médecine ont déjà (ADR-136).
 *
 * Rattachée à l'auteur autant qu'au passage : sur un poste partagé, une
 * sage-femme ne doit jamais hériter — puis enregistrer sous son nom — ce
 * qu'une collègue a saisi sans le valider.
 *
 * Le payload est une carte de sections (record, procedure, cesarean),
 * jetables, jamais une vérité clinique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maternity_record_drafts', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete : un brouillon ne porte aucune histoire à protéger.
            $table->foreignId('episode_orientation_id')
                ->constrained('episode_orientations')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['episode_orientation_id', 'created_by'], 'mrd_orientation_author_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maternity_record_drafts');
    }
};
