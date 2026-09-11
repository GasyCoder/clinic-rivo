<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Site-only: populated exclusively on a clinic deployment when RH
    // generates a document. Never written on the portal (admin.rivo.mg
    // never touches a clinic's employees/contracts/leave requests).
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_template_id')->constrained('document_templates')->restrictOnDelete();
            // Frozen at generation time: a later template edit/version must
            // never change how an already-generated document is labeled.
            $table->string('template_name_snapshot');
            $table->string('document_type_snapshot', 80);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('employment_contract_id')->nullable()->constrained('employment_contracts')->restrictOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            $table->json('resolved_variables_snapshot');
            $table->json('manual_variables_snapshot')->nullable();
            // The fully merged, print-ready HTML at the moment of
            // generation — never recomputed afterward (same guarantee as
            // EmploymentContract.template_variables_snapshot, ADR-069).
            $table->longText('rendered_html_snapshot');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['employee_id', 'document_type_snapshot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
