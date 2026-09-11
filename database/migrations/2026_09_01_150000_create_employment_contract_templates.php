<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_contract_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('contract_type_id')->constrained('hr_reference_values')->restrictOnDelete();
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 150);
            $table->string('file_format', 10);
            $table->unsignedBigInteger('size');
            $table->json('placeholders')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['contract_type_id', 'name']);
        });

        Schema::table('employment_contracts', function (Blueprint $table) {
            $table->foreignId('contract_template_id')->nullable()->after('contract_type_id')
                ->constrained('employment_contract_templates')->restrictOnDelete();
            $table->json('template_variables_snapshot')->nullable()->after('contract_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_template_id');
            $table->dropColumn('template_variables_snapshot');
        });

        Schema::dropIfExists('employment_contract_templates');
    }
};
