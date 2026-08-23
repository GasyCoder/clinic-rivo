<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->boolean('care_requires_allergy_check')
                ->default(false)
                ->after('reception_routing_mode');
            $table->boolean('care_recommends_vitals')
                ->default(false)
                ->after('care_requires_allergy_check');
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->boolean('care_requires_allergy_check')
                ->default(false)
                ->after('routing_mode');
            $table->boolean('care_recommends_vitals')
                ->default(false)
                ->after('care_requires_allergy_check');
        });

        Schema::table('care_records', function (Blueprint $table) {
            $table->text('no_procedure_reason')
                ->nullable()
                ->after('smoker');
        });

        Schema::table('care_record_procedures', function (Blueprint $table) {
            $table->timestamp('allergy_checked_at')
                ->nullable()
                ->after('notes');
        });

        DB::table('catalog_items')
            ->whereIn('code', ['INJECTION-IM', 'INJECTION-IV', 'PERFUSION'])
            ->update(['care_requires_allergy_check' => true]);

        DB::table('episode_service_requests')
            ->whereIn('catalog_code', ['INJECTION-IM', 'INJECTION-IV', 'PERFUSION'])
            ->update(['care_requires_allergy_check' => true]);
    }

    public function down(): void
    {
        Schema::table('care_record_procedures', function (Blueprint $table) {
            $table->dropColumn('allergy_checked_at');
        });

        Schema::table('care_records', function (Blueprint $table) {
            $table->dropColumn('no_procedure_reason');
        });

        Schema::table('episode_service_requests', function (Blueprint $table) {
            $table->dropColumn(['care_requires_allergy_check', 'care_recommends_vitals']);
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['care_requires_allergy_check', 'care_recommends_vitals']);
        });
    }
};
