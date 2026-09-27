<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-105 — un examen paraclinique demandé par le médecin rejoint le compte
 * du patient. La ligne de la demande porte l'élément facturable qu'elle a
 * produit, afin qu'une annulation (ADR-079) retrouve exactement ce qu'elle
 * doit annuler — jamais une déduction par le nom ou la date.
 *
 * Nullable : les demandes déjà enregistrées n'ont jamais été facturées, et
 * leur en attribuer une après coup inventerait une créance que personne n'a
 * constatée.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['lab_request_items', 'imaging_request_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('billable_item_id')
                    ->nullable()
                    ->after('catalog_item_id')
                    ->constrained('billable_items')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['lab_request_items', 'imaging_request_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('billable_item_id');
            });
        }
    }
};
