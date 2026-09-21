<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-162 — le séjour devient le poste de travail du patient hospitalisé.
 *
 * Ordonnances, analyses, imagerie, demandes de soins, transferts et sortie
 * étaient tous obligatoirement rattachés à une consultation : pour un patient
 * au lit, le médecin devait donc rouvrir l'assistant Médecine (visite de
 * service, ADR-148) pour la moindre ligne d'ordonnance. Ils peuvent désormais
 * être rattachés au séjour lui-même.
 *
 * - `hospital_stay_id` (nullable) sur chaque demande : d'où elle vient.
 * - `consultation_id` devient nullable là où il ne l'était pas : une demande
 *   du séjour n'a pas de consultation, et n'en fabrique pas.
 * - `prescriptions.episode_id` : l'ordonnance ne connaissait son passage qu'à
 *   travers sa consultation. Repris de la consultation pour l'existant, sans
 *   rien inventer.
 * - `pharmacy_dispenses.hospital_stay_id` : la délivrance au service, qui
 *   n'attend pas le règlement (amende l'ADR-049 pour ce seul cas).
 * - `hospital_stay_notes` : la note quotidienne courte S/O/A/P.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $consultationRequired = ['prescriptions', 'imaging_requests', 'care_orders', 'medical_discharges', 'medical_referrals'];

    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('episode_id')->nullable()->after('id')->constrained('episodes')->restrictOnDelete();
        });

        DB::table('prescriptions')
            ->whereNull('episode_id')
            ->update(['episode_id' => DB::raw('(select episode_id from consultations where consultations.id = prescriptions.consultation_id)')]);

        foreach (['prescriptions', 'lab_requests', 'imaging_requests', 'care_orders', 'medical_referrals', 'pharmacy_dispenses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('hospital_stay_id')->nullable()->after('episode_id')
                    ->constrained('hospital_stays')->restrictOnDelete();
            });
        }

        foreach ($this->consultationRequired as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('consultation_id')->nullable()->change();
            });
        }

        Schema::create('hospital_stay_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospital_stay_id')->constrained('hospital_stays')->restrictOnDelete();
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->timestamp('written_at');
            $table->foreignId('written_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hospital_stay_id', 'written_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_stay_notes');

        foreach (['prescriptions', 'lab_requests', 'imaging_requests', 'care_orders', 'medical_referrals', 'pharmacy_dispenses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('hospital_stay_id');
            });
        }

        // Une demande du séjour n'a pas de consultation : la rendre
        // obligatoire n'est possible que s'il n'en existe aucune.
        foreach ($this->consultationRequired as $name) {
            if (DB::table($name)->whereNull('consultation_id')->doesntExist()) {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedBigInteger('consultation_id')->nullable(false)->change();
                });
            }
        }

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('episode_id');
        });
    }
};
