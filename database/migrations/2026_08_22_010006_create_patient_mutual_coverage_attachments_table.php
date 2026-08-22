<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_mutual_coverage_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('patient_mutual_coverage_id');
            $table->foreign('patient_mutual_coverage_id', 'pmca_coverage_fk')
                ->references('id')
                ->on('patient_mutual_coverages')
                ->restrictOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['patient_mutual_coverage_id', 'created_at'], 'patient_mutual_attachments_coverage_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_mutual_coverage_attachments');
    }
};
