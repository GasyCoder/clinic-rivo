<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-098 — the supplier's own classification, declared in its price list.
 *
 * A family belongs to the clinic's medicine, not to the supplier; what is
 * stored here is only what the file said, verbatim, so the catalogue can be
 * read by family and the family can be *proposed* when the product enters
 * the clinic catalogue. It is never a foreign key to medicine_categories:
 * a supplier's wording is not the clinic's referential.
 *
 * Optional column: the catalogues already imported carry none, and a file
 * without it stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->string('family_label', 120)->nullable()->after('presentation');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->dropColumn('family_label');
        });
    }
};
