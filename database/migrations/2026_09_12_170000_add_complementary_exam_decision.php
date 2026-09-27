<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The decision "are complementary exams needed?" is a finding of the clinical
 * examination, so it is recorded where it is made.
 *
 * Nullable on purpose: null means the doctor has not answered yet, and is
 * never rendered as "no". The step status alone could not carry this — it
 * cannot tell "not decided" from "yes, but nothing ordered yet".
 *
 * The requests themselves stay in their own modules; nothing about a lab or
 * imaging order is copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_examinations', function (Blueprint $table) {
            $table->boolean('complementary_exams_required')->nullable()->after('general_observation');
        });

        // Until now a request sent to the Laboratory or to Imaging could not
        // be taken back: there was no cancellation at all, so changing one's
        // mind would have meant deleting clinical data — which ADR-010
        // forbids. Cancellation is additive and never touches a result.
        foreach (['lab_requests', 'imaging_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable()->after('requested_at');
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                    ->constrained('users')->nullOnDelete();
                $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
            });
        }
    }

    public function down(): void
    {
        foreach (['lab_requests', 'imaging_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('cancelled_by');
                $table->dropColumn(['cancelled_at', 'cancel_reason']);
            });
        }

        Schema::table('clinical_examinations', function (Blueprint $table) {
            $table->dropColumn('complementary_exams_required');
        });
    }
};
