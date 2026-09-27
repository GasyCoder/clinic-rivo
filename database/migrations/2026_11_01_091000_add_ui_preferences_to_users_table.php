<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-191 — ce que chaque utilisateur ajuste dans « Mon profil » : taille du
 * texte, animations, contraste. Gardé sur le compte, pas sur le poste : un poste
 * de soins est partagé, et les réglages d'une personne ne doivent pas suivre la
 * suivante. Vide, les valeurs du site s'appliquent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('ui_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ui_preferences');
        });
    }
};
