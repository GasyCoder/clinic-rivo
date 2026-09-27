<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-209, amendement du 2026-09-27 (bis) — le badge se met en page au format du
 * porte-badge (les inserts courants, ou un format sur mesure en millimètres) et
 * porte un QR code de son numéro.
 *
 * Les formats proposés s'ajoutent aux choix de `badge_card_size` (une chaîne de
 * 16 caractères au plus) : aucune colonne pour eux. Le format sur mesure garde
 * ses deux côtés ; le QR, son interrupteur. Vides : 105 × 149 mm (lu seulement
 * pour « Sur mesure ») et le QR affiché.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('badge_card_width')->nullable();
            $table->unsignedSmallInteger('badge_card_height')->nullable();
            $table->boolean('badge_show_qr')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn(['badge_card_width', 'badge_card_height', 'badge_show_qr']);
        });
    }
};
