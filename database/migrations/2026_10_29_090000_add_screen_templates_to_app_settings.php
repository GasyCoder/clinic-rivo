<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les modèles d'écran et l'image de fond des pages d'authentification
 * (ADR-184, amendement du 2026-09-24).
 *
 * Trois colonnes vides par défaut : un site que personne n'a réglé garde le
 * modèle « Couverture » et l'image de la clinique qu'il affichait déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            // Connexion, mot de passe oublié, réinitialisation et activation de compte.
            $table->string('auth_template', 20)->nullable()->after('search_engines_hidden');
            $table->string('auth_background_path')->nullable()->after('auth_template');
            // La page « Mon profil ».
            $table->string('profile_template', 20)->nullable()->after('auth_background_path');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn(['auth_template', 'auth_background_path', 'profile_template']);
        });
    }
};
