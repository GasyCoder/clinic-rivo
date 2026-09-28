<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-213 — les avantages et primes d'un employé (logement, transport, repas,
 * téléphone, assurance, prime…) : un type, un montant, un motif, une fréquence.
 *
 * Une déclaration du RH, comme la rémunération (ADR-206) : aucun total, aucune
 * retenue ni net n'en est calculé (ADR-066). Mêmes droits confidentiels,
 * `employees.payroll.*` : aucune permission nouvelle.
 *
 * Qui y a droit se règle par fonction, dans le module Fonctions (métadonnée
 * `benefits_eligible`) : « Médecin » est cochée d'office — arbitrage du
 * propriétaire —, jamais par-dessus une décision déjà prise.
 */
return new class extends Migration
{
    /** @var array<string, string> Les types nommés par le propriétaire ; la liste reste modifiable. */
    private const TYPES = [
        'LODGING' => 'Logement',
        'TRANSPORT' => 'Transport',
        'MEALS' => 'Repas',
        'PHONE' => 'Téléphone',
        'INSURANCE' => 'Assurance',
        'BONUS' => 'Prime',
        'OTHER' => 'Autre',
    ];

    public function up(): void
    {
        Schema::create('employee_benefits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('benefit_type_id')->constrained('hr_reference_values')->restrictOnDelete();
            // Facultatif : un avantage en nature (un logement fourni) n'a pas toujours de montant.
            $table->decimal('amount', 15, 2)->nullable();
            $table->text('reason');
            // MONTHLY (chaque mois) ou ONE_TIME (une fois) — EmployeeBenefitFrequency.
            $table->string('frequency', 20);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            // ADR-187 — le Super Admin agit depuis le portail sans compte local.
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['employee_id', 'deleted_at']);
        });

        $now = now();
        $position = 0;

        foreach (self::TYPES as $code => $label) {
            $exists = DB::table('hr_reference_values')->where('type', 'BENEFIT_TYPE')->where('code', $code)->exists();

            if (! $exists) {
                DB::table('hr_reference_values')->insert([
                    'uuid' => (string) Str::uuid(),
                    'type' => 'BENEFIT_TYPE',
                    'code' => $code,
                    'label' => $label,
                    'active' => true,
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $position++;
        }

        // « Médecin » ouvre droit aux avantages, sauf décision déjà prise sur cette fonction.
        DB::table('hr_reference_values')->where('type', 'JOB_TITLE')->where('code', 'DOCTOR')->get(['id', 'metadata'])
            ->each(function (object $jobTitle) use ($now): void {
                $metadata = json_decode((string) ($jobTitle->metadata ?? ''), true) ?: [];

                if (array_key_exists('benefits_eligible', $metadata)) {
                    return;
                }

                DB::table('hr_reference_values')->where('id', $jobTitle->id)->update([
                    'metadata' => json_encode([...$metadata, 'benefits_eligible' => true]),
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_benefits');

        DB::table('hr_reference_values')->where('type', 'BENEFIT_TYPE')->delete();
    }
};
