<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->change();
            $table->uuid('external_created_by_uuid')->nullable()->index();
            $table->string('external_created_by_name', 150)->nullable();
            $table->uuid('external_updated_by_uuid')->nullable()->index();
            $table->string('external_updated_by_name', 150)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('analysis_catalogs')->whereNotNull('external_created_by_uuid')->exists()
            || DB::table('analysis_catalogs')->whereNotNull('external_updated_by_uuid')->exists()) {
            throw new RuntimeException(
                'Rollback refusé : des actions Super Administration distantes sont historisées dans le catalogue des analyses.',
            );
        }

        Schema::table('analysis_catalogs', function (Blueprint $table) {
            $table->dropColumn([
                'external_created_by_uuid', 'external_created_by_name',
                'external_updated_by_uuid', 'external_updated_by_name',
            ]);
            $table->foreignId('created_by')->nullable(false)->change();
            $table->foreignId('updated_by')->nullable(false)->change();
        });
    }
};
