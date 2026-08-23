<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('episode_orientation_id')
                ->nullable()
                ->after('episode_id')
                ->constrained('episode_orientations')
                ->restrictOnDelete();
            $table->unique('episode_orientation_id');
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('prescribed_by')
                ->nullable()
                ->after('consultation_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('prescribed_at')->nullable()->after('status');
        });

        Schema::create('medical_discharges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->unique()->constrained('episodes')->restrictOnDelete();
            $table->foreignId('consultation_id')->unique()->constrained('consultations')->restrictOnDelete();
            $table->string('type');
            $table->text('final_diagnosis');
            $table->text('patient_condition');
            $table->text('discharge_prescription')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->text('observations')->nullable();
            $table->string('transfer_destination')->nullable();
            $table->timestamp('death_occurred_at')->nullable();
            $table->string('death_place')->nullable();
            $table->text('death_causes')->nullable();
            $table->timestamp('discharged_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'discharged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_discharges');

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescribed_by');
            $table->dropColumn('prescribed_at');
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropUnique(['episode_orientation_id']);
            $table->dropConstrainedForeignId('episode_orientation_id');
        });
    }
};
