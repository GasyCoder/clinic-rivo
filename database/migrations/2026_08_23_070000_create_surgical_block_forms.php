<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_block_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->unique()->constrained('surgical_requests')->restrictOnDelete();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('temperature_celsius', 4, 2)->nullable();
            $table->unsignedSmallInteger('blood_pressure_systolic')->nullable();
            $table->unsignedSmallInteger('blood_pressure_diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedTinyInteger('oxygen_saturation')->nullable();
            $table->boolean('full_bath_completed')->nullable();
            $table->boolean('weighing_completed')->nullable();
            $table->unsignedTinyInteger('peripheral_iv_count')->nullable();
            $table->string('serum_name')->nullable();
            $table->decimal('serum_quantity', 10, 2)->nullable();
            $table->string('serum_unit', 30)->nullable();
            $table->boolean('urinary_catheter_placed')->nullable();
            $table->decimal('diuresis_quantity', 10, 2)->nullable();
            $table->string('diuresis_unit', 30)->nullable();
            $table->string('urine_appearance')->nullable();
            $table->dateTime('catheter_placed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('surgical_block_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->unique()->constrained('surgical_requests')->restrictOnDelete();
            $table->dateTime('block_entered_at')->nullable();
            $table->dateTime('block_exited_at')->nullable();

            foreach (['entry', 'exit'] as $phase) {
                $table->unsignedSmallInteger("{$phase}_blood_pressure_systolic")->nullable();
                $table->unsignedSmallInteger("{$phase}_blood_pressure_diastolic")->nullable();
                $table->unsignedSmallInteger("{$phase}_heart_rate")->nullable();
                $table->unsignedTinyInteger("{$phase}_oxygen_saturation")->nullable();
                $table->unsignedSmallInteger("{$phase}_respiratory_rate")->nullable();
                $table->decimal("{$phase}_temperature_celsius", 4, 2)->nullable();
            }

            $table->string('perfusion_serum')->nullable();
            $table->string('perfusion_bag')->nullable();
            $table->string('transfusion_blood')->nullable();
            $table->decimal('transfusion_quantity', 10, 2)->nullable();
            $table->string('transfusion_unit', 30)->nullable();
            $table->string('urine_appearance')->nullable();
            $table->decimal('urine_quantity', 10, 2)->nullable();
            $table->string('urine_unit', 30)->nullable();
            $table->decimal('blood_loss_quantity', 10, 2)->nullable();
            $table->string('blood_loss_unit', 30)->nullable();
            $table->string('drug_name')->nullable();
            $table->decimal('drug_quantity', 10, 2)->nullable();
            $table->string('drug_unit', 30)->nullable();
            $table->string('antibiotic_name')->nullable();
            $table->decimal('antibiotic_quantity', 10, 2)->nullable();
            $table->string('antibiotic_unit', 30)->nullable();
            $table->string('awakening_status')->nullable();
            $table->string('awakening_score', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('surgical_postoperative_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->dateTime('observed_at');
            $table->decimal('diuresis_quantity', 10, 2)->nullable();
            $table->string('diuresis_unit', 30)->nullable();
            $table->decimal('temperature_celsius', 4, 2)->nullable();
            $table->unsignedSmallInteger('blood_pressure_systolic')->nullable();
            $table->unsignedSmallInteger('blood_pressure_diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedTinyInteger('oxygen_saturation')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['surgical_request_id', 'observed_at'], 'surgical_postop_observations_request_time_index');
        });

        Schema::create('surgical_treatment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgical_request_id')->constrained('surgical_requests')->restrictOnDelete();
            $table->string('phase');
            $table->string('category');
            $table->string('label');
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 30)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['surgical_request_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_treatment_items');
        Schema::dropIfExists('surgical_postoperative_observations');
        Schema::dropIfExists('surgical_block_exits');
        Schema::dropIfExists('surgical_block_entries');
    }
};
