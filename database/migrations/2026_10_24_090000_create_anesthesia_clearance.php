<?php

use App\Enums\AnesthesiaClearanceConditionStatus;
use App\Enums\AnesthesiaClearanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-170 — la décision anesthésique d'autoriser le passage au bloc devient un
 * fait à part entière : un statut, une date, un auteur, un motif.
 *
 * Elle vivait dans `paraclinical_data.surgery_authorized`, un booléen enfoui
 * dans un JSON, sans auteur ni date, et que rien ne lisait pour bloquer quoi
 * que ce soit. Cette colonne JSON **n'est pas supprimée ni migrée** : elle
 * porte ce que des anesthésistes ont réellement saisi, et en déduire une
 * décision datée que personne n'a prononcée inventerait un fait clinique
 * (ADR-074). Les dossiers déjà saisis restent donc `DRAFT` — « personne n'a
 * encore décidé » — ce qui est exact, et l'écran le dit.
 *
 * `assessment_validated_at` garde son sens : l'évaluation pré-anesthésique est
 * terminée et verrouillée. Ce sont deux faits distincts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anesthesia_records', function (Blueprint $table) {
            $table->string('clearance_status', 32)
                ->default(AnesthesiaClearanceStatus::Draft->value)
                ->after('assessment_validated_at');
            $table->foreignId('clearance_decided_by')->nullable()->after('clearance_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('clearance_decided_at')->nullable()->after('clearance_decided_by');
            $table->text('clearance_reason')->nullable()->after('clearance_decided_at');
            // Une autorisation peut n'être valable qu'un temps (bilan daté) :
            // facultatif, jamais deviné, et seulement bloquant s'il est dépassé.
            $table->timestamp('clearance_valid_until')->nullable()->after('clearance_reason');

            $table->index(['clearance_status', 'surgical_request_id'], 'anesthesia_records_clearance_index');
        });

        Schema::create('anesthesia_clearance_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anesthesia_record_id')->constrained('anesthesia_records')->cascadeOnDelete();
            $table->string('label');
            $table->string('status', 20)->default(AnesthesiaClearanceConditionStatus::Open->value);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            // Index nommé : le nom déduit fait 65 caractères, un de trop pour
            // MySQL. SQLite l'accepterait — les tests n'auraient rien vu.
            $table->index(['anesthesia_record_id', 'status'], 'clearance_conditions_record_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anesthesia_clearance_conditions');

        Schema::table('anesthesia_records', function (Blueprint $table) {
            $table->dropIndex('anesthesia_records_clearance_index');
            $table->dropConstrainedForeignId('clearance_decided_by');
            $table->dropColumn([
                'clearance_status', 'clearance_decided_at',
                'clearance_reason', 'clearance_valid_until',
            ]);
        });
    }
};
