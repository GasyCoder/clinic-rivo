<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('blood_pressure_left_systolic')
                ->nullable()
                ->after('blood_group');
            $table->unsignedSmallInteger('blood_pressure_left_diastolic')
                ->nullable()
                ->after('blood_pressure_left_systolic');
            $table->unsignedSmallInteger('blood_pressure_right_systolic')
                ->nullable()
                ->after('blood_pressure_left_diastolic');
            $table->unsignedSmallInteger('blood_pressure_right_diastolic')
                ->nullable()
                ->after('blood_pressure_right_systolic');
            $table->decimal('temperature_celsius', 4, 2)
                ->nullable()
                ->after('blood_pressure_right_diastolic');
            $table->boolean('known_diabetes')
                ->nullable()
                ->after('temperature_celsius');
        });
    }

    public function down(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->dropColumn([
                'blood_pressure_left_systolic',
                'blood_pressure_left_diastolic',
                'blood_pressure_right_systolic',
                'blood_pressure_right_diastolic',
                'temperature_celsius',
                'known_diabetes',
            ]);
        });
    }
};
