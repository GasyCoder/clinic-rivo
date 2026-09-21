<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-167 — la dernière reprise d'une prise en charge Soins.
 *
 * `accepted_by` désigne toujours le soignant responsable ; ces colonnes disent
 * seulement qu'il a repris le patient à un collègue, quand et pourquoi.
 * `accepted_at` n'est jamais réécrit : c'est le début réel des soins, et la
 * remise en file (ADR-122) compte le travail enregistré depuis ce moment-là.
 * L'historique complet des reprises reste à l'audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_orientations', function (Blueprint $table): void {
            $table->timestamp('taken_over_at')->nullable()->after('accepted_at');
            $table->foreignId('taken_over_from')->nullable()->after('taken_over_at')->constrained('users')->nullOnDelete();
            $table->text('takeover_reason')->nullable()->after('taken_over_from');
        });
    }

    public function down(): void
    {
        Schema::table('episode_orientations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('taken_over_from');
            $table->dropColumn(['taken_over_at', 'takeover_reason']);
        });
    }
};
