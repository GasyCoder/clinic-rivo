<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-163 — revenir sur un geste fait depuis le séjour, sans rien effacer.
 *
 * Une visite de service annulée et une demande au bloc retirée restent en base,
 * avec leur auteur, leur date et le motif de leur annulation (ADR-010) : on sait
 * qu'elles ont existé, et pourquoi elles n'ont pas eu de suite.
 *
 * Aucune ligne existante n'est rétro-remplie : une demande annulée avant cette
 * colonne l'a été sans trace d'auteur, et en inventer une fabriquerait un fait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
        });

        Schema::table('surgical_requests', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('discharge_notes');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('surgical_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
