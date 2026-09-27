<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employment_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('attestation_type_id')->nullable()->constrained('hr_reference_values')->restrictOnDelete();
            $table->string('category', 30)->index();
            $table->string('title');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->date('issued_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['employee_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_documents');
    }
};
