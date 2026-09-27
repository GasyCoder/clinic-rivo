<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_reference_values')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_shifts');
    }
};
