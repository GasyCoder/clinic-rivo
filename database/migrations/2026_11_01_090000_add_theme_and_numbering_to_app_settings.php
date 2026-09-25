<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-191 — thème, réglages avancés et numérotation de chaque site.
 *
 * Toutes les colonnes sont vides par défaut : un site que personne n'a réglé
 * garde exactement ses couleurs, sa taille de texte et ses numéros d'avant
 * (A-26-0001, A-26-0001-01). Aucun numéro déjà attribué n'est touché.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            // Thème : `primary_color` reste la couleur principale du mode clair.
            $table->string('theme_preset', 40)->nullable()->after('primary_color');
            $table->string('light_background', 7)->nullable()->after('theme_preset');
            $table->string('light_foreground', 7)->nullable()->after('light_background');
            $table->string('dark_primary_color', 7)->nullable()->after('light_foreground');
            $table->string('dark_background', 7)->nullable()->after('dark_primary_color');
            $table->string('dark_foreground', 7)->nullable()->after('dark_background');

            // Réglages avancés : les valeurs par défaut du site ; chacun ajuste les siennes dans « Mon profil ».
            $table->unsignedTinyInteger('ui_font_size')->nullable()->after('dark_foreground');
            $table->string('ui_density', 20)->nullable()->after('ui_font_size');
            $table->string('ui_radius', 20)->nullable()->after('ui_density');
            $table->string('ui_motion', 20)->nullable()->after('ui_radius');
            $table->string('ui_contrast', 20)->nullable()->after('ui_motion');

            // Numérotation des patients et des passages : vide = le format de l'ADR-030.
            $table->string('patient_number_prefix', 12)->nullable()->after('child_max_age');
            $table->string('patient_number_year', 4)->nullable()->after('patient_number_prefix');
            $table->unsignedTinyInteger('patient_number_digits')->nullable()->after('patient_number_year');
            $table->string('patient_number_separator', 1)->nullable()->after('patient_number_digits');
            $table->string('patient_number_reset', 10)->nullable()->after('patient_number_separator');
            $table->unsignedTinyInteger('episode_number_digits')->nullable()->after('patient_number_reset');

            // Matricule des employés : le modèle de la proposition automatique.
            $table->string('employee_number_prefix', 12)->nullable()->after('episode_number_digits');
            $table->string('employee_number_separator', 1)->nullable()->after('employee_number_prefix');
            $table->unsignedTinyInteger('employee_number_digits')->nullable()->after('employee_number_separator');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'theme_preset', 'light_background', 'light_foreground',
                'dark_primary_color', 'dark_background', 'dark_foreground',
                'ui_font_size', 'ui_density', 'ui_radius', 'ui_motion', 'ui_contrast',
                'patient_number_prefix', 'patient_number_year', 'patient_number_digits',
                'patient_number_separator', 'patient_number_reset', 'episode_number_digits',
                'employee_number_prefix', 'employee_number_separator', 'employee_number_digits',
            ]);
        });
    }
};
