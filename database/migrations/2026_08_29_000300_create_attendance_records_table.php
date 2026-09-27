<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index(['work_date', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
