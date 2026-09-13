<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conduite à tenir becomes a business datum instead of a screen at the
 * end of the wizard (ADR-084).
 *
 * Until now a doctor who already knew at the examination that the patient
 * needed surgery had to reach a "Décision" step to say so a second time,
 * and only then got the request form. The decision is recorded where it is
 * actually taken, and the matching request opens immediately.
 *
 * Strictly additive:
 *
 *   - `consultations.decision` is neither dropped nor rewritten. It keeps
 *     being written alongside, so the passage detail page, the episode API
 *     and every consultation recorded before this migration stay readable
 *     exactly as they are.
 *   - `consultation_steps` rows carrying `step = 'decision'` are untouched.
 *     The enum case survives; only the wizard stops offering it.
 *
 * Two request tables are created here, and deliberately only two. Chirurgie
 * already has `surgical_requests`, Maternité has its own workspace, and a
 * medical discharge has `medical_discharges`. Hospitalisation and
 * Référence/Transfert had nothing at all: their request lived as free text
 * inside an orientation's `reason`.
 *
 * What these two tables hold is the DEMANDE — what the doctor asks for.
 * They deliberately do not model the admission or the transfer itself: who
 * admits, which bed, which site, who closes the stay are defined nowhere in
 * the CDC, and inventing them here would fabricate clinical process. Their
 * status is therefore REQUESTED or CANCELLED, never ADMITTED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_orientations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();

            $table->string('type', 20);
            $table->string('status', 20);
            // Nullable: not every destination is asked to act by a deadline,
            // and a blank priority is not "normale" decided by default.
            $table->string('priority', 10)->nullable();

            // The real business object this orientation produced. One nullable
            // key per destination that owns a record of its own, rather than a
            // single JSON blob: a surgical request must stay a surgical
            // request, queryable and constrained by the database.
            $table->foreignId('episode_orientation_id')->nullable()->constrained('episode_orientations')->nullOnDelete();
            $table->foreignId('surgical_request_id')->nullable()->constrained('surgical_requests')->nullOnDelete();
            $table->foreignId('medical_discharge_id')->nullable()->constrained('medical_discharges')->nullOnDelete();
            $table->unsignedBigInteger('hospitalization_request_id')->nullable();
            $table->unsignedBigInteger('medical_referral_id')->nullable();

            $table->foreignId('selected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('selected_at');
            $table->timestamp('submitted_at')->nullable();

            // Changing one's mind is legitimate; erasing the first decision is
            // not (ADR-010). A cancelled orientation keeps its row, its author
            // and its request, and only releases `active_key`.
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            // Same nullable-unique guard as `cash_sessions` and
            // `episode_orientations`: exactly one active orientation per
            // consultation, enforced by the database rather than by a race
            // between two browser tabs.
            $table->string('active_key')->nullable()->unique();

            $table->timestamps();
            $table->index(['consultation_id', 'status'], 'consult_orientations_status_index');
        });

        Schema::create('hospitalization_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->foreignId('episode_orientation_id')->nullable()->constrained('episode_orientations')->nullOnDelete();

            $table->text('reason');
            $table->text('admission_diagnosis')->nullable();
            $table->text('clinical_summary')->nullable();
            $table->text('planned_treatment')->nullable();
            $table->string('requested_service', 150)->nullable();
            // What the doctor asks for, never a recorded admission: the
            // admission itself belongs to the Hospitalisation module that does
            // not exist yet (ADR-032, ADR-074).
            $table->timestamp('requested_admission_at')->nullable();
            $table->string('priority', 10);
            $table->text('instructions')->nullable();

            $table->string('status', 20);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('medical_referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->foreignId('episode_orientation_id')->nullable()->constrained('episode_orientations')->nullOnDelete();

            $table->string('facility', 255);
            $table->text('reason');
            $table->text('diagnosis')->nullable();
            $table->text('clinical_summary')->nullable();
            // Already given or already prescribed: the receiving team needs to
            // know both, and the doctor must not have to retype either.
            $table->text('treatments_given')->nullable();
            $table->string('priority', 10);
            $table->text('recommendations')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20);
            $table->foreignId('referred_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('referred_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
        });

        // Added after both tables exist. Named explicitly: the conventional
        // name Laravel derives for the hospitalisation key is 60 characters
        // and the one for its index would be longer still — MySQL refuses
        // identifiers over 64, and the failure would only appear in
        // production, never on the SQLite the tests run on.
        Schema::table('consultation_orientations', function (Blueprint $table) {
            $table->foreign('hospitalization_request_id', 'consult_orientations_hosp_request_fk')
                ->references('id')->on('hospitalization_requests')->nullOnDelete();
            $table->foreign('medical_referral_id', 'consult_orientations_referral_fk')
                ->references('id')->on('medical_referrals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consultation_orientations', function (Blueprint $table) {
            $table->dropForeign('consult_orientations_hosp_request_fk');
            $table->dropForeign('consult_orientations_referral_fk');
        });

        Schema::dropIfExists('medical_referrals');
        Schema::dropIfExists('hospitalization_requests');
        Schema::dropIfExists('consultation_orientations');
    }
};
