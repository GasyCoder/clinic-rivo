<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clinical examination stops being one large blob of HTML.
 *
 * `consultations.clinical_exam` held everything the doctor observed as free
 * text, which makes three clinically distinct facts indistinguishable: a
 * system examined and normal, a system examined with an anomaly, and a
 * system never examined at all. Nothing could be read back, counted or
 * checked.
 *
 * Strictly additive. `consultations.clinical_exam` is neither dropped nor
 * rewritten: it remains the home of the free "Notes cliniques
 * complémentaires", still displayed by the passage detail page, so no
 * existing record loses anything and no content has to be migrated.
 *
 * Vital signs are deliberately absent from both tables. They are recorded
 * once by Soins on `care_records` and reach the doctor read-only through
 * `CareRecordReadModel` (ADR-054); re-entering them here would create a
 * second, conflicting truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_examinations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            // One examination per consultation: re-examining during the same
            // encounter corrects this record rather than starting a rival one.
            // A genuine second encounter opens its own consultation.
            $table->foreignId('consultation_id')->unique()->constrained('consultations')->cascadeOnDelete();

            // All nullable: the doctor may record systems without judging the
            // overall condition, and nothing is ever pre-filled.
            $table->string('general_condition', 20)->nullable();
            $table->string('consciousness_status', 20)->nullable();
            $table->string('consciousness_details', 500)->nullable();
            $table->text('general_observation')->nullable();

            $table->foreignId('examined_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('examined_at');
            $table->timestamps();
        });

        Schema::create('clinical_examination_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_examination_id')
                ->constrained('clinical_examinations')
                ->cascadeOnDelete();
            $table->string('system_code', 30);
            // No default. A row exists only because the doctor said something
            // about this system, and the column must never quietly mean
            // "normal" for a system nobody looked at.
            $table->string('status', 20);
            $table->text('findings')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per system and per examination: the status is a state,
            // not a log. Who recorded it and when live on the parent, and the
            // Auditable trait keeps every transition.
            //
            // Named explicitly: the conventional name Laravel would derive
            // here is 71 characters, over MySQL's 64-character identifier
            // limit, and the create would fail on MySQL while passing on the
            // SQLite used by the tests.
            $table->unique(['clinical_examination_id', 'system_code'], 'clinical_findings_exam_system_unique');
            $table->index('status', 'clinical_findings_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_examination_findings');
        Schema::dropIfExists('clinical_examinations');
    }
};
