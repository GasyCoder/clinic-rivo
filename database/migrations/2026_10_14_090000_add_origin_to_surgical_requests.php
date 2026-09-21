<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-159 — la demande du bloc dit d'où elle vient.
 *
 * Nullable, et jamais rétro-rempli : une demande enregistrée avant cette
 * colonne ne portait aucune origine, et la déduire d'une date ou d'un auteur
 * inventerait un fait (ADR-083). L'écran affiche alors « Origine non
 * renseignée » plutôt qu'un tiret muet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgical_requests', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('surgical_requests', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
