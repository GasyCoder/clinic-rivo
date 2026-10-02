<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-243 — les stagiaires ont leur propre série de matricules (`STG-0001` par
 * défaut), distincte de celle des employés : un stagiaire ne prend plus le
 * prochain numéro d'employé. Vide, le préfixe d'origine s'applique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->string('intern_number_prefix', 12)->nullable()->after('employee_number_digits');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn('intern_number_prefix');
        });
    }
};
