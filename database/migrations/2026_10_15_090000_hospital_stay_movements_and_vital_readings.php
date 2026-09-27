<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-161 — lot 1 du séjour hospitalier : ce qui était faux, corrigé.
 *
 * - `end_reason` et `medical_referral_id` : un séjour dit comment il s'est
 *   terminé, y compris par un départ en transfert, qui ne porte aucune sortie
 *   médicale et laissait jusqu'ici le séjour ouvert.
 * - `hospital_stay_movements` : où est le patient, depuis quand. Le service et
 *   le lit s'écrasaient ; ils deviennent un historique.
 * - `vital_sign_readings` : la surveillance répétée. `care_records` est unique
 *   par passage et reste le relevé de triage.
 *
 * Reprise de l'existant sans rien inventer : le motif de fin est lu sur la
 * sortie médicale réellement prononcée, et chaque séjour reçoit un premier
 * mouvement à son service et son lit connus, daté de son admission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospital_stays', function (Blueprint $table) {
            $table->string('end_reason', 30)->nullable()->after('status');
            $table->foreignId('medical_referral_id')->nullable()->after('medical_discharge_id')
                ->constrained('medical_referrals')->nullOnDelete();
        });

        Schema::create('hospital_stay_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospital_stay_id')->constrained('hospital_stays')->restrictOnDelete();
            $table->string('service', 150)->nullable();
            $table->string('room_bed', 100)->nullable();
            $table->string('care_level', 30)->default('STANDARD');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('reason')->nullable();
            // Nul seulement pour la reprise de l'existant : l'auteur réel d'une
            // admission antérieure est `admitted_by`, repris quand il existe.
            $table->foreignId('moved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hospital_stay_id', 'ended_at']);
        });

        Schema::create('vital_sign_readings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            $table->foreignId('hospital_stay_id')->nullable()->constrained('hospital_stays')->restrictOnDelete();
            $table->timestamp('measured_at');
            $table->unsignedSmallInteger('blood_pressure_systolic')->nullable();
            $table->unsignedSmallInteger('blood_pressure_diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->decimal('temperature_celsius', 4, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('measured_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hospital_stay_id', 'measured_at']);
        });

        $reasons = [
            'NORMAL' => 'HOME',
            'TRANSFER' => 'TRANSFER',
            'AT_PATIENT_REQUEST' => 'AGAINST_ADVICE',
            'MEDICAL_DECISION_REFUSAL' => 'DECISION_REFUSAL',
            'DECEASED' => 'DECEASED',
        ];

        DB::table('hospital_stays')
            ->where('status', 'DISCHARGED')
            ->whereNotNull('medical_discharge_id')
            ->orderBy('id')
            ->each(function ($stay) use ($reasons) {
                $type = DB::table('medical_discharges')->where('id', $stay->medical_discharge_id)->value('type');

                if (isset($reasons[$type])) {
                    DB::table('hospital_stays')->where('id', $stay->id)->update(['end_reason' => $reasons[$type]]);
                }
            });

        DB::table('hospital_stays')->orderBy('id')->each(function ($stay) {
            DB::table('hospital_stay_movements')->insert([
                'uuid' => (string) Str::uuid(),
                'hospital_stay_id' => $stay->id,
                'service' => $stay->service,
                'room_bed' => $stay->room_bed,
                'care_level' => 'STANDARD',
                'started_at' => $stay->admitted_at ?? $stay->created_at,
                'ended_at' => $stay->discharged_at ?? $stay->cancelled_at,
                'moved_by' => $stay->admitted_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vital_sign_readings');
        Schema::dropIfExists('hospital_stay_movements');

        Schema::table('hospital_stays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medical_referral_id');
            $table->dropColumn('end_reason');
        });
    }
};
