<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-032 (23/08/2026) required blood pressure on both arms; a same-day
 * follow-up from the owner replaces that with a single reading (TA) and
 * adds heart rate (FC) and oxygen saturation (SpO2) instead — see the
 * ADR-032 amendment note in docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->dropColumn([
                'blood_pressure_left_systolic',
                'blood_pressure_left_diastolic',
                'blood_pressure_right_systolic',
                'blood_pressure_right_diastolic',
            ]);
        });

        Schema::table('care_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('blood_pressure_systolic')
                ->nullable()
                ->after('blood_group');
            $table->unsignedSmallInteger('blood_pressure_diastolic')
                ->nullable()
                ->after('blood_pressure_systolic');
            $table->unsignedSmallInteger('heart_rate')
                ->nullable()
                ->after('temperature_celsius');
            $table->unsignedTinyInteger('spo2')
                ->nullable()
                ->after('heart_rate');
        });
    }

    public function down(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->dropColumn(['blood_pressure_systolic', 'blood_pressure_diastolic', 'heart_rate', 'spo2']);
        });

        Schema::table('care_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('blood_pressure_left_systolic')->nullable()->after('blood_group');
            $table->unsignedSmallInteger('blood_pressure_left_diastolic')->nullable()->after('blood_pressure_left_systolic');
            $table->unsignedSmallInteger('blood_pressure_right_systolic')->nullable()->after('blood_pressure_left_diastolic');
            $table->unsignedSmallInteger('blood_pressure_right_diastolic')->nullable()->after('blood_pressure_right_systolic');
        });
    }
};
