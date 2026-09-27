<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-177 — la prochaine étape suggérée par la Réception, une ligne par service.
 *
 * Une table plutôt qu'une colonne `episodes.next_step` : la suggestion est
 * facultative **et** peut désigner plusieurs services (Soins et Médecine), ce
 * qu'une colonne unique limiterait artificiellement.
 *
 * Donnée organisationnelle, jamais une orientation : aucune ligne
 * d'`episode_orientations` n'en est déduite, et les anciennes orientations ne
 * sont pas converties en suggestions — une orientation passée peut représenter
 * une vraie décision métier, et en tirer une suggestion inventerait un
 * historique. Un passage antérieur n'a donc simplement aucune suggestion.
 *
 * Noms d'index et de clés posés à la main : MySQL refuse un identifiant de plus
 * de 64 caractères, et SQLite ne le signale jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episode_reception_next_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id');
            $table->string('module', 32);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique(['episode_id', 'module'], 'episode_next_steps_unique');
            $table->foreign('episode_id', 'episode_next_steps_episode_fk')
                ->references('id')->on('episodes')->cascadeOnDelete();
            $table->foreign('created_by', 'episode_next_steps_creator_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_reception_next_steps');
    }
};
