<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicine_lots', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->change();
            $table->uuid('external_created_by_uuid')->nullable()->after('updated_by')->index();
            $table->string('external_created_by_name', 150)->nullable()->after('external_created_by_uuid');
            $table->uuid('external_updated_by_uuid')->nullable()->after('external_created_by_name')->index();
            $table->string('external_updated_by_name', 150)->nullable()->after('external_updated_by_uuid');
        });

        Schema::table('pharmacy_stock_movements', function (Blueprint $table) {
            $table->foreignId('performed_by')->nullable()->change();
            $table->uuid('external_actor_uuid')->nullable()->after('performed_by')->index();
            $table->string('external_actor_name', 150)->nullable()->after('external_actor_uuid');
        });
    }

    public function down(): void
    {
        $fallbackUserId = DB::table('users')->value('id');

        if ($fallbackUserId !== null) {
            DB::table('medicine_lots')->whereNull('created_by')->update(['created_by' => $fallbackUserId]);
            DB::table('medicine_lots')->whereNull('updated_by')->update(['updated_by' => $fallbackUserId]);
            DB::table('pharmacy_stock_movements')->whereNull('performed_by')->update(['performed_by' => $fallbackUserId]);
        }

        Schema::table('pharmacy_stock_movements', function (Blueprint $table) {
            $table->dropColumn(['external_actor_uuid', 'external_actor_name']);

            if (DB::table('pharmacy_stock_movements')->whereNull('performed_by')->doesntExist()) {
                $table->foreignId('performed_by')->nullable(false)->change();
            }
        });

        Schema::table('medicine_lots', function (Blueprint $table) {
            $table->dropColumn([
                'external_created_by_uuid', 'external_created_by_name',
                'external_updated_by_uuid', 'external_updated_by_name',
            ]);

            if (DB::table('medicine_lots')->whereNull('created_by')->doesntExist()) {
                $table->foreignId('created_by')->nullable(false)->change();
            }

            if (DB::table('medicine_lots')->whereNull('updated_by')->doesntExist()) {
                $table->foreignId('updated_by')->nullable(false)->change();
            }
        });
    }
};
