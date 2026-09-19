<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-113 (amendement) — la demande d'hospitalisation part en un clic : son
 * motif est repris du dossier quand il existe, puis complété dans le module
 * Hospitalisation. Une absence reste une absence, jamais une chaîne vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitalization_requests', function (Blueprint $table) {
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Irréversible sans perte : des demandes ont pu partir sans motif.
    }
};
