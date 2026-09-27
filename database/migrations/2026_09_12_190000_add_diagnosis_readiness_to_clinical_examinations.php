<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Can the diagnosis be made now?" — asked at the end of the clinical
 * examination so that a straightforward consultation concludes in one screen
 * instead of crossing a step of its own.
 *
 * Nullable, like the complementary-exam decision beside it: null means the
 * doctor has not answered, and is never rendered as "no". Answering "non"
 * is what a doctor does while waiting for results; it leaves the Diagnostic
 * step open rather than forcing a conclusion before the evidence.
 *
 * The diagnoses themselves stay in `diagnoses`, append-only (ADR-035);
 * nothing about them is copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_examinations', function (Blueprint $table) {
            $table->boolean('diagnosis_ready')->nullable()->after('complementary_exams_required');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_examinations', function (Blueprint $table) {
            $table->dropColumn('diagnosis_ready');
        });
    }
};
