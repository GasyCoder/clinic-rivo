<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-244 — un modèle de contrat ou de congé peut viser des types précis
     * (CDI, CDD… ; maladie, maternité…), désignés par le code stable du
     * référentiel RH du site. Vide : modèle général, pour tous les types.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('applies_to')->nullable()->after('data_context');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('applies_to');
        });
    }
};
