<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->unique()->constrained('episodes')->restrictOnDelete();
            $table->string('blood_group', 3)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->text('allergy_note')->nullable();
            $table->boolean('smoker')->nullable();
            $table->text('hospitalization_reason')->nullable();
            $table->dateTime('hospitalized_at')->nullable();
            $table->dateTime('discharged_at')->nullable();
            $table->text('diagnostic_note')->nullable();
            $table->text('transmission_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('care_record_procedures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('care_record_id')->constrained('care_records')->restrictOnDelete();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->restrictOnDelete();
            $table->uuid('catalog_item_uuid');
            $table->string('procedure_code', 60);
            $table->string('procedure_name');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('performed_at');
            $table->timestamps();

            $table->index(['care_record_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_record_procedures');
        Schema::dropIfExists('care_records');
    }
};
