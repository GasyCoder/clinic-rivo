<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields of the clinic's paper "DOSSIER MÉDICAL" that the application did
 * not yet collect: birth place, the personal/familial split of antecedents,
 * and the treatments the patient is already taking on arrival.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('birth_place', 150)->nullable()->after('birth_date_is_approximate');
        });

        Schema::table('patient_antecedents', function (Blueprint $table) {
            // Existing rows keep PERSONAL: that is what the form they were
            // entered on collected. Inferring "familial" afterwards would
            // invent a clinical fact.
            $table->string('type', 20)->default('PERSONAL')->after('patient_id');
            $table->index(['patient_id', 'type']);
        });

        // What the patient is already taking when seen. Recorded on the
        // consultation, not on the patient: it is what was true at this
        // encounter, and it changes between visits (same reasoning as the
        // per-episode emergency contact, ADR-034).
        //
        // Deliberately NOT linked to Pharmacy stock and carrying no price:
        // this is declarative information, not a prescription (ADR-036/037).
        Schema::create('consultation_current_treatments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->string('medication_name');
            $table->string('dosage', 150)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['consultation_id', 'position'], 'cct_consultation_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_current_treatments');

        Schema::table('patient_antecedents', function (Blueprint $table) {
            $table->dropIndex(['patient_id', 'type']);
            $table->dropColumn('type');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('birth_place');
        });
    }
};
