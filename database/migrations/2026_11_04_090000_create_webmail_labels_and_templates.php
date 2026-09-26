<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-195 — la messagerie ne garde aucun message dans RIVO : ils restent chez
 * l'hébergeur. Elle ne range que ce qui appartient au compte lui-même :
 *
 *   ses libellés   un nom, une couleur et le mot-clé IMAP posé sur les messages ;
 *   ses modèles    des messages types à insérer en rédigeant.
 *
 * Aucune permission : c'est son propre espace, comme « Mon profil ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_labels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 20);
            // Le mot-clé posé sur les messages (IMAP) : lettres et chiffres, jamais changé.
            $table->string('keyword', 40);
            $table->timestamps();

            $table->unique(['user_id', 'keyword']);
            $table->unique(['user_id', 'name']);
        });

        Schema::create('webmail_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('subject', 255)->nullable();
            $table->mediumText('body_html');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_templates');
        Schema::dropIfExists('webmail_labels');
    }
};
