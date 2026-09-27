<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-087: retires the {{variable}} substitution mechanism (ADR-070) in
 * favor of a fixed page-1 form filled by the RH. `variables_used` was only
 * ever an extraction artifact of the removed panel; the two snapshot
 * columns it fed are replaced by a single `form_data_snapshot`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('variables_used');
        });

        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropColumn(['resolved_variables_snapshot', 'manual_variables_snapshot']);
            $table->json('form_data_snapshot')->after('leave_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('variables_used')->nullable();
        });

        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropColumn('form_data_snapshot');
            $table->json('resolved_variables_snapshot')->nullable();
            $table->json('manual_variables_snapshot')->nullable();
        });
    }
};
