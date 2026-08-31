<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_type_id')->constrained('hr_reference_values')->restrictOnDelete();
            $table->string('reference_number')->nullable()->unique();
            $table->date('signed_on')->nullable();
            $table->date('starts_on');
            $table->date('trial_ends_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['employee_id', 'starts_on']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_contracts');
    }
};
