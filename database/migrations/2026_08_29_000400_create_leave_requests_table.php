<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('interim_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('leave_address')->nullable();
            $table->string('emergency_phone', 50)->nullable();
            $table->decimal('days_requested', 6, 2)->nullable();
            $table->decimal('remaining_days_snapshot', 6, 2)->nullable();
            $table->text('reason');
            $table->date('requested_on');
            $table->date('starts_on');
            $table->date('returns_on');
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'starts_on', 'returns_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
