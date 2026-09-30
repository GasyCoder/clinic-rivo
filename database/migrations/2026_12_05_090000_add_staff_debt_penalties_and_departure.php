<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-230 — pénalités de retard et règlement au départ des dettes du personnel.
 *
 * Réglages du site : un taux mensuel sur le seul montant en retard, un délai de grâce et un
 * plafond. Chaque dette fige la règle en vigueur à son accord (aucune dette déjà accordée
 * n'en reçoit une) ; chaque pénalité liquidée est une ligne, une par mois de retard, jamais
 * supprimée, remissible par le DG. Le règlement au départ garde ses conditions sur la dette.
 *
 * Aucune permission nouvelle : remettre une pénalité suit `staff_debts.write_off`, régler
 * un départ `staff_debts.decide` (et `write_off` pour une remise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_debt_settings', function (Blueprint $table): void {
            $table->decimal('penalty_rate', 5, 2)->nullable()->after('interest_tiers');
            $table->unsignedSmallInteger('penalty_grace_days')->nullable()->after('penalty_rate');
            $table->decimal('penalty_cap_rate', 5, 2)->nullable()->after('penalty_grace_days');
        });

        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->decimal('penalty_rate', 5, 2)->nullable()->after('interest_waived');
            $table->unsignedSmallInteger('penalty_grace_days')->nullable()->after('penalty_rate');
            $table->decimal('penalty_cap_rate', 5, 2)->nullable()->after('penalty_grace_days');
            // Ce qui était déjà remboursé quand l'échéancier a repris : le retard se compte depuis là.
            $table->decimal('schedule_offset', 14, 2)->default(0)->after('penalty_cap_rate');
            $table->timestamp('departure_settled_at')->nullable()->after('arrears_notified_for');
            $table->foreignId('departure_settled_by')->nullable()->after('departure_settled_at')->constrained('users')->nullOnDelete();
            $table->uuid('external_departure_settled_by_uuid')->nullable()->after('departure_settled_by');
            $table->string('external_departure_settled_by_name')->nullable()->after('external_departure_settled_by_uuid');
            $table->json('departure_terms')->nullable()->after('external_departure_settled_by_name');
        });

        Schema::create('staff_debt_penalties', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('staff_debt_id')->constrained('staff_debts')->restrictOnDelete();
            // Le mois dont les remboursements étaient en retard.
            $table->date('period');
            $table->decimal('base_amount', 14, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamp('assessed_at');
            $table->timestamp('waived_at')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_waived_by_uuid')->nullable();
            $table->string('external_waived_by_name')->nullable();
            $table->string('waiver_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(['staff_debt_id', 'period'], 'staff_debt_penalties_debt_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_debt_penalties');

        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('departure_settled_by');
        });

        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->dropColumn([
                'penalty_rate', 'penalty_grace_days', 'penalty_cap_rate', 'schedule_offset',
                'departure_settled_at', 'external_departure_settled_by_uuid', 'external_departure_settled_by_name', 'departure_terms',
            ]);
        });

        Schema::table('staff_debt_settings', function (Blueprint $table): void {
            $table->dropColumn(['penalty_rate', 'penalty_grace_days', 'penalty_cap_rate']);
        });
    }
};
