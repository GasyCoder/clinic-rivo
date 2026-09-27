<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            // `reason` remains the rich-text history of the present illness.
            // Keeping it avoids duplicating or rewriting historical notes.
            $table->string('chief_complaint')->nullable()->after('doctor_id')->index();
            $table->string('symptom_onset', 150)->nullable()->after('reason');
            $table->string('evolution', 30)->nullable()->after('symptom_onset');
            $table->text('additional_notes')->nullable()->after('evolution');
            $table->string('known_treatment_change', 10)->nullable()->after('additional_notes');
            $table->text('known_treatment_change_notes')->nullable()->after('known_treatment_change');
            $table->json('reported_allergies')->nullable()->after('known_treatment_change_notes');
            $table->json('reported_antecedents')->nullable()->after('reported_allergies');
            $table->json('reported_habitual_treatments')->nullable()->after('reported_antecedents');
            $table->foreignId('interviewed_by')->nullable()->after('reported_habitual_treatments')->constrained('users')->nullOnDelete();
            $table->timestamp('interviewed_at')->nullable()->after('interviewed_by');
        });

        Schema::table('consultation_current_treatments', function (Blueprint $table) {
            $table->string('frequency', 150)->nullable()->after('dosage');
            $table->string('duration', 150)->nullable()->after('frequency');
            $table->string('source', 30)->default('PATIENT_REPORTED')->after('duration');
        });

        Schema::create('patient_treatments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('medication_name');
            $table->string('dosage', 150)->nullable();
            $table->string('frequency', 150)->nullable();
            $table->string('duration', 150)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['patient_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_treatments');

        Schema::table('consultation_current_treatments', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'duration', 'source']);
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('interviewed_by');
            $table->dropColumn([
                'chief_complaint', 'symptom_onset', 'evolution', 'additional_notes',
                'known_treatment_change', 'known_treatment_change_notes',
                'reported_allergies', 'reported_antecedents',
                'reported_habitual_treatments', 'interviewed_at',
            ]);
        });
    }
};
