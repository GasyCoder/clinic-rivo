<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Groups every version of "the same logical canevas" so a history screen
    // can list them together and a revert can find "the currently active
    // version of this lineage" without walking a linked list. A version
    // created fresh (or via duplication, ADR-070) starts a new lineage of
    // its own; a version created to replace an in-use template (
    // SaveDocumentTemplateAction) inherits the lineage of the one it
    // replaces.
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->uuid('lineage_id')->nullable()->after('uuid');
        });

        // Every row that already exists predates this feature: each one is,
        // by definition, the sole member of its own lineage so far.
        DB::table('document_templates')->whereNull('lineage_id')->update([
            'lineage_id' => DB::raw('uuid'),
        ]);

        Schema::table('document_templates', function (Blueprint $table) {
            $table->uuid('lineage_id')->nullable(false)->change();
            $table->index('lineage_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('lineage_id');
        });
    }
};
