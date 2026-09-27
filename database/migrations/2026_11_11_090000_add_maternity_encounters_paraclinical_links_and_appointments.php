<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-204 — la Maternité distingue consultation prénatale et accouchement,
 * demande ses propres examens et programme un rendez-vous.
 *
 * Rien n'est supprimé ni réécrit :
 *
 *  - `encounter_type` reste vide sur les dossiers antérieurs : aucun parcours
 *    n'est déduit d'un contenu enregistré avant que le choix existe ;
 *  - `maternity_record_id` ne désigne que les demandes faites depuis la
 *    Maternité ; une demande de consultation ou de séjour n'en a pas ;
 *  - un rendez-vous n'est jamais un passage : aucune colonne `episode_id`.
 *
 * Les noms d'index et de clés sont écrits à la main : MySQL refuse un
 * identifiant de plus de 64 caractères.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maternity_records', function (Blueprint $table) {
            $table->string('encounter_type', 20)->nullable()->after('pregnancy_id');
            $table->index(['encounter_type', 'completed_at'], 'maternity_records_encounter_idx');
        });

        Schema::table('lab_requests', function (Blueprint $table) {
            $table->foreignId('maternity_record_id')->nullable()->after('hospital_stay_id');
            // L'index d'abord : la clé étrangère s'appuie sur lui au lieu d'en créer un second.
            $table->index('maternity_record_id', 'lab_requests_maternity_record_idx');
            $table->foreign('maternity_record_id', 'lab_requests_maternity_record_fk')
                ->references('id')->on('maternity_records')->restrictOnDelete();
        });

        Schema::table('imaging_requests', function (Blueprint $table) {
            $table->foreignId('maternity_record_id')->nullable()->after('hospital_stay_id');
            // L'index d'abord : la clé étrangère s'appuie sur lui au lieu d'en créer un second.
            $table->index('maternity_record_id', 'imaging_requests_maternity_record_idx');
            $table->foreign('maternity_record_id', 'imaging_requests_maternity_record_fk')
                ->references('id')->on('maternity_records')->restrictOnDelete();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('pregnancy_id')->nullable()->constrained('pregnancies')->restrictOnDelete();
            // Une consultation programme au plus un rendez-vous : la clé unique porte aussi la clé étrangère.
            $table->foreignId('source_maternity_record_id')->nullable();
            $table->dateTime('scheduled_at');
            $table->string('reason', 190);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('SCHEDULED');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'scheduled_at'], 'appointments_patient_scheduled_idx');
            $table->index(['pregnancy_id', 'status'], 'appointments_pregnancy_status_idx');
            $table->unique('source_maternity_record_id', 'appointments_source_record_unique');
            $table->foreign('source_maternity_record_id', 'appointments_source_maternity_record_fk')
                ->references('id')->on('maternity_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');

        Schema::table('imaging_requests', function (Blueprint $table) {
            $table->dropForeign('imaging_requests_maternity_record_fk');
            $table->dropIndex('imaging_requests_maternity_record_idx');
            $table->dropColumn('maternity_record_id');
        });

        Schema::table('lab_requests', function (Blueprint $table) {
            $table->dropForeign('lab_requests_maternity_record_fk');
            $table->dropIndex('lab_requests_maternity_record_idx');
            $table->dropColumn('maternity_record_id');
        });

        Schema::table('maternity_records', function (Blueprint $table) {
            $table->dropIndex('maternity_records_encounter_idx');
            $table->dropColumn('encounter_type');
        });
    }
};
