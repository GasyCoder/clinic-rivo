<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-209 — l'apparence du badge du personnel, réglée par site depuis le portail :
 * deux couleurs, la devise, l'emblème et ce que le badge affiche.
 *
 * Toutes les colonnes sont vides par défaut : un site que personne n'a réglé
 * imprime le badge bleu et jaune de la clinique, avec son emblème.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->string('badge_primary_color', 7)->nullable()->after('profile_template');
            $table->string('badge_accent_color', 7)->nullable()->after('badge_primary_color');
            $table->string('badge_tagline', 80)->nullable()->after('badge_accent_color');
            // SEAL : l'emblème dans un sceau au nom de l'établissement ; LOGO : l'image telle quelle.
            $table->string('badge_logo_style', 16)->nullable()->after('badge_tagline');
            $table->string('badge_logo_path')->nullable()->after('badge_logo_style');
            // Vide = affiché : ce que le modèle de la clinique montre.
            $table->boolean('badge_show_tagline')->nullable()->after('badge_logo_path');
            $table->boolean('badge_show_icon')->nullable()->after('badge_show_tagline');
            $table->boolean('badge_show_number')->nullable()->after('badge_show_icon');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'badge_primary_color', 'badge_accent_color', 'badge_tagline', 'badge_logo_style',
                'badge_logo_path', 'badge_show_tagline', 'badge_show_icon', 'badge_show_number',
            ]);
        });
    }
};
