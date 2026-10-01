<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-239 — un mode de paiement « Banque » désigne une banque du référentiel
 * du site (ADR-221), et un mode « Autre » nomme sa catégorie en clair.
 *
 * Les modes génériques déjà en service (« Chèque », « Virement bancaire ») ne
 * sont reliés à aucune banque : leur en choisir une inventerait une donnée
 * métier. Ils restent valides tels quels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->foreignId('bank_id')
                ->nullable()
                ->after('category')
                ->constrained('banks', indexName: 'payment_methods_bank_fk')
                ->restrictOnDelete();
            $table->string('category_detail', 60)->nullable()->after('bank_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropForeign('payment_methods_bank_fk');
            $table->dropColumn(['bank_id', 'category_detail']);
        });
    }
};
