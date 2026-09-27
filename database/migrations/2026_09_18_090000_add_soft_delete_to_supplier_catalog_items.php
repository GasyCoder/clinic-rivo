<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-098 — a catalogue line can now be corrected and withdrawn. Withdrawing
 * it is a Soft Delete like everywhere else (ADR-009): the line leaves the
 * screens with its reason and its author, and comes back if it was a
 * mistake. Nothing is destroyed — an offer may already point at it.
 *
 * Re-importing the file stays a physical replacement (the service uses
 * forceDelete): a re-read is a new transcription of the same document, not
 * a hundred lines to keep in a bin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->softDeletesWithReason();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_catalog_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['deleted_at', 'delete_reason']);
        });
    }
};
