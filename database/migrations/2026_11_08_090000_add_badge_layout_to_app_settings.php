<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-209, amendement du 2026-09-27 — tout ce qui se règle sur le badge du
 * personnel : couleurs du texte et du fond, textes (sceau, stagiaire, numéro,
 * pied), éléments affichés, icône, polices et tailles, disposition de la carte
 * (portrait ou paysage, taille, photo, coins) et impression (papier, orientation,
 * marges, espacement, traits de coupe).
 *
 * Toutes les colonnes sont vides par défaut : un site que personne n'a réglé
 * imprime exactement le badge d'avant, en planche A4 portrait.
 */
return new class extends Migration
{
    private const STRINGS = [
        'badge_text_color' => 7,
        'badge_background_color' => 7,
        'badge_seal_top' => 30,
        'badge_seal_bottom' => 40,
        'badge_intern_label' => 24,
        'badge_number_label' => 12,
        'badge_footer_text' => 60,
        'badge_icon' => 24,
        'badge_font' => 16,
        'badge_tagline_font' => 16,
        'badge_name_case' => 16,
        'badge_name_order' => 16,
        'badge_text_case' => 16,
        'badge_orientation' => 16,
        'badge_card_size' => 16,
        'badge_photo_shape' => 16,
        'badge_corners' => 16,
        'badge_paper' => 16,
        'badge_paper_orientation' => 16,
    ];

    private const NUMBERS = ['badge_name_size', 'badge_text_size', 'badge_tagline_size', 'badge_page_margin', 'badge_gap'];

    private const SWITCHES = [
        'badge_show_photo', 'badge_show_department', 'badge_show_job', 'badge_show_validity',
        'badge_show_site', 'badge_show_watermark', 'badge_show_decorations', 'badge_cut_marks',
    ];

    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            foreach (self::STRINGS as $column => $length) {
                $table->string($column, $length)->nullable();
            }

            foreach (self::NUMBERS as $column) {
                $table->unsignedSmallInteger($column)->nullable();
            }

            // Vide = la valeur du modèle de la clinique (tout affiché, sauf le nom du site).
            foreach (self::SWITCHES as $column) {
                $table->boolean($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn([...array_keys(self::STRINGS), ...self::NUMBERS, ...self::SWITCHES]);
        });
    }
};
