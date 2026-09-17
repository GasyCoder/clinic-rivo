<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un catalogue fournisseur est d'abord une liste de ce que le fournisseur
 * propose : le tarif arrive parfois séparément, ou plus tard. Exiger un prix
 * sur chaque ligne rendait tout le fichier invalide — constaté sur un
 * catalogue réel de 119 produits, sans un seul prix (ADR-098).
 *
 * Une ligne sans prix s'importe donc, et c'est le rattachement au catalogue
 * clinique qui le réclame : c'est lui qui crée le prix d'achat versionné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table): void {
            $table->decimal('supplier_price', 15, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table): void {
            $table->decimal('supplier_price', 15, 2)->nullable(false)->change();
        });
    }
};
