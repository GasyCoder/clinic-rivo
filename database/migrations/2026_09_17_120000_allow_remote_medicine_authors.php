<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-098 — ordering a line of a supplier catalogue creates the clinic
 * medicine at that moment, and that order can be written from the central
 * portal. The remote Super Admin has no local account: the local author of
 * a medicine and of a supplier price becomes optional and the portal
 * identity is kept beside it, exactly like orders and supplier invoices
 * (2026_09_14_180000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->change();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name')->nullable();
        });

        Schema::table('medicine_supplier_offers', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->uuid('external_ended_by_uuid')->nullable();
            $table->string('external_ended_by_name')->nullable();
        });
    }

    public function down(): void
    {
        // `created_by` stays nullable: rows written from the portal have none.
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn([
                'external_created_by_uuid', 'external_created_by_name',
                'external_updated_by_uuid', 'external_updated_by_name',
            ]);
        });

        Schema::table('medicine_supplier_offers', function (Blueprint $table) {
            $table->dropColumn([
                'external_created_by_uuid', 'external_created_by_name',
                'external_ended_by_uuid', 'external_ended_by_name',
            ]);
        });
    }
};
