<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_cancellations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('diagnosis_id')->unique()->constrained('diagnoses')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosis_cancellations');
    }
};
