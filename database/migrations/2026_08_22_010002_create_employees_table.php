<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('employee_number')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('civility', 20)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->string('sex', 1);
            $table->date('birth_date')->nullable();
            $table->string('identity_document_type', 20)->nullable();
            $table->string('identity_document_number', 100)->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->unsignedSmallInteger('children_count')->nullable();
            $table->string('profession')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('address_entry_id')->nullable()->constrained('address_entries')->restrictOnDelete();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['last_name', 'first_name']);
            $table->index('identity_document_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
