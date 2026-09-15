<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-098 — supplier catalogs are managed from the central portal, whose
 * Super Admin has no local account on the site. A catalog uploaded that way
 * keeps the portal identity it came from, as medicine_lots already does for
 * the central stock import (ADR-042); its parsed lines may have no local
 * author at all. The audit log records the remote actor in both cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalogs', function (Blueprint $table) {
            $table->uuid('external_created_by_uuid')->nullable()->after('created_by');
            $table->string('external_created_by_name', 150)->nullable()->after('external_created_by_uuid');
        });

        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Lines imported from the portal have no local author and would
        // violate the former NOT NULL: they must be removed before a rollback.
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable(false)->change();
        });

        Schema::table('supplier_catalogs', function (Blueprint $table) {
            $table->dropColumn(['external_created_by_uuid', 'external_created_by_name']);
        });
    }
};
