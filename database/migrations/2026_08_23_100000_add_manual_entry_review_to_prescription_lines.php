<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor may prescribe a medicine absent from the Pharmacy catalog so an
 * ordonnance is never blocked by catalog incompleteness. Such a line keeps
 * `medicine_id` null (no stock reservation, no price ever resolved) and is
 * flagged for review by whoever holds `catalog.items.create` — the same
 * permission ADR-024 already reserves for referential changes, not a new
 * one invented for this flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table) {
            $table->boolean('is_manual_entry')->default(false)->after('medicine_id');
            $table->string('catalog_review_status', 20)->nullable()->after('is_manual_entry');
            $table->foreignId('catalog_reviewed_by')->nullable()->after('catalog_review_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('catalog_reviewed_at')->nullable()->after('catalog_reviewed_by');
            $table->text('catalog_review_note')->nullable()->after('catalog_reviewed_at');

            $table->index(['is_manual_entry', 'catalog_review_status']);
        });
    }

    public function down(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table) {
            $table->dropIndex(['is_manual_entry', 'catalog_review_status']);
            $table->dropConstrainedForeignId('catalog_reviewed_by');
            $table->dropColumn(['is_manual_entry', 'catalog_review_status', 'catalog_reviewed_at', 'catalog_review_note']);
        });
    }
};
