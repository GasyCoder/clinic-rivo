<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Until now the wizard's progress existed only in the browser: Vue derived
 * "this step is done" from whether any data happened to be present. That
 * cannot distinguish a step the doctor deliberately declared unnecessary
 * from one simply left blank, and it showed Prescription as complete
 * whenever any prescription existed — even with no diagnosis recorded.
 *
 * Step progress becomes server-owned state, and the consultation gains its
 * own lifecycle status instead of inferring closure from the presence of a
 * MedicalDischarge.
 *
 * Strictly additive: no column is dropped or rewritten, and the backfill
 * below only reads what is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->string('step', 30);
            $table->string('status', 20)->default('NOT_STARTED');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            // A skipped step carries why it was not needed: "no complementary
            // exam required" is a clinical statement, not an empty field.
            $table->string('skip_reason', 500)->nullable();
            $table->timestamps();

            // One row per step and per consultation: the status is a state,
            // never an append-only log. What must stay traceable — who
            // resolved a step and when — lives on the row itself, and the
            // Auditable trait records each transition.
            $table->unique(['consultation_id', 'step']);
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->string('status', 20)->default('IN_PROGRESS')->after('doctor_id');
            $table->timestamp('completed_at')->nullable()->after('consulted_at');
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['status', 'consulted_at']);
        });

        // Backfill from facts already recorded, never from a guess:
        //   - a discharge exists   -> the encounter did end -> COMPLETED
        //   - nothing written yet  -> DRAFT
        //   - anything written     -> IN_PROGRESS
        // The default above already covers IN_PROGRESS, so only the two
        // other cases need writing.
        DB::table('consultations')
            ->whereIn('id', fn ($query) => $query
                ->select('consultation_id')
                ->from('medical_discharges')
                ->whereNotNull('consultation_id'))
            ->update(['status' => 'COMPLETED']);

        DB::table('consultations')
            ->where(fn ($query) => $query->whereNull('reason')->orWhere('reason', ''))
            ->where(fn ($query) => $query->whereNull('clinical_exam')->orWhere('clinical_exam', ''))
            ->whereNotIn('id', fn ($query) => $query
                ->select('consultation_id')
                ->from('medical_discharges')
                ->whereNotNull('consultation_id'))
            ->update(['status' => 'DRAFT']);

        // Existing consultations keep their history: a step whose content is
        // already recorded is marked COMPLETED so the stepper does not
        // pretend a doctor's past work never happened. Steps with nothing
        // recorded stay NOT_STARTED rather than being invented as skipped.
        $this->backfillSteps();
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropIndex(['status', 'consulted_at']);
            $table->dropColumn(['status', 'completed_at']);
        });

        Schema::dropIfExists('consultation_steps');
    }

    private function backfillSteps(): void
    {
        $now = now();

        DB::table('consultations')->orderBy('id')->chunkById(200, function ($consultations) use ($now): void {
            $rows = [];

            foreach ($consultations as $consultation) {
                $completed = ['dossier'];

                if (filled($consultation->reason)) {
                    $completed[] = 'consultation';
                }

                if (filled($consultation->clinical_exam)) {
                    $completed[] = 'examen';
                }

                if (DB::table('lab_requests')->where('consultation_id', $consultation->id)->exists()
                    || DB::table('imaging_requests')->where('consultation_id', $consultation->id)->exists()) {
                    $completed[] = 'paraclinique';
                }

                if (DB::table('diagnoses')->where('consultation_id', $consultation->id)->exists()) {
                    $completed[] = 'diagnostic';
                }

                if (DB::table('prescriptions')->where('consultation_id', $consultation->id)->exists()) {
                    $completed[] = 'ordonnance';
                }

                if (DB::table('medical_discharges')->where('consultation_id', $consultation->id)->exists()) {
                    $completed[] = 'decision';
                }

                foreach ($completed as $step) {
                    $rows[] = [
                        'consultation_id' => $consultation->id,
                        'step' => $step,
                        'status' => 'COMPLETED',
                        // The real moment is unknown for historical rows;
                        // attributing it to a user would invent an actor, so
                        // completed_by stays null and the timestamp is the
                        // migration's own.
                        'completed_at' => $now,
                        'completed_by' => null,
                        'skip_reason' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('consultation_steps')->insert($rows);
            }
        });
    }
};
