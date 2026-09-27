<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-094 — un passage paraclinique seul se clôt sans diagnostic final.
 *
 * La colonne était NOT NULL. Y écrire une chaîne vide rendrait « aucun
 * diagnostic » indiscernable d'« un diagnostic oublié », exactement le
 * défaut que l'ADR-077 refuse pour les appareils non examinés : une absence
 * doit rester une absence. Elle devient donc nullable.
 *
 * Aucune ligne existante n'est réécrite : toutes portent un diagnostic, et
 * elles restent telles quelles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_discharges', function (Blueprint $table) {
            $table->text('final_diagnosis')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('medical_discharges', function (Blueprint $table) {
            $table->text('final_diagnosis')->nullable(false)->change();
        });
    }
};
