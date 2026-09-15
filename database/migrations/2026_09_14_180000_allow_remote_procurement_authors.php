<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-098 — orders and supplier invoices can be written from the central
 * portal. The remote Super Admin has no local account: the local author
 * becomes optional and the portal identity is kept beside it, exactly like
 * supplier catalogs. The audit log records the remote actor as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->change();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name')->nullable();
            $table->uuid('external_cancelled_by_uuid')->nullable();
            $table->string('external_cancelled_by_name')->nullable();
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->change();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name')->nullable();
        });
    }

    public function down(): void
    {
        // The local authors stay nullable: rows written from the portal have none.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'external_created_by_uuid', 'external_created_by_name',
                'external_updated_by_uuid', 'external_updated_by_name',
                'external_cancelled_by_uuid', 'external_cancelled_by_name',
            ]);
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'external_created_by_uuid', 'external_created_by_name',
                'external_updated_by_uuid', 'external_updated_by_name',
            ]);
        });
    }
};
