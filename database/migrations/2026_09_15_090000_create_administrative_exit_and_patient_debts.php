<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CDC §33.3 — the administrative exit, and the receivable it creates when
 * the account is not settled. Additive only: nothing existing is rewritten,
 * and `administrative_status` keeps its own column (§21's rule that the
 * medical, financial and administrative statuses stay separate).
 *
 * The exit balance is snapshotted on the episode because §34.2 rule 8
 * forbids treating a debt exit as a settled invoice: the amount that was
 * actually owed at the moment the patient walked out must stay readable
 * even if invoices are later paid, cancelled or corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('administrative_exit_type', 30)
                ->nullable()
                ->after('administrative_status');
            $table->timestamp('administrative_exit_at')
                ->nullable()
                ->after('administrative_exit_type');
            $table->foreignId('administrative_exit_by')
                ->nullable()
                ->after('administrative_exit_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->decimal('administrative_exit_balance', 14, 2)
                ->nullable()
                ->after('administrative_exit_by');
            $table->text('administrative_exit_reason')
                ->nullable()
                ->after('administrative_exit_balance');

            // The settlement queue is read by exit state, newest first.
            $table->index(
                ['administrative_status', 'started_at'],
                'episodes_administrative_status_started_index',
            );
        });

        // The receivable itself. A financial record: never deleted, and
        // never silently erased by an escape (§34.2 rule 9). No settlement
        // workflow is modelled here — the CDC defines none, and the
        // Créances module (roadmap Phase 1) will own that when its rules
        // are decided. What this table guarantees today is that the debt
        // exists, is attributable, and cannot disappear.
        Schema::create('patient_debts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('debt_number')->unique();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('episode_id')->constrained('episodes')->restrictOnDelete();
            // Which exit produced it: DEBT_VALIDATED or ESCAPED. They are
            // not the same fact and must stay distinguishable in reports.
            $table->string('origin', 30);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('MGA');
            $table->text('reason');

            // §33.3 "dette validée": personne responsable du paiement,
            // coordonnées, échéance éventuelle, commentaire, utilisateur
            // ayant autorisé. Nullable because an escape has none of them —
            // by definition nobody committed to paying.
            $table->string('responsible_name')->nullable();
            $table->string('responsible_phone', 50)->nullable();
            $table->string('responsible_relationship', 100)->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();

            // §33.3 "évadé": heure estimée du départ, service, observations.
            $table->timestamp('left_at_estimate')->nullable();
            $table->string('last_known_service')->nullable();

            $table->text('comment')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
            $table->index(['origin', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_debts');

        Schema::table('episodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('administrative_exit_by');
            $table->dropIndex('episodes_administrative_status_started_index');
            $table->dropColumn([
                'administrative_exit_type',
                'administrative_exit_at',
                'administrative_exit_balance',
                'administrative_exit_reason',
            ]);
        });
    }
};
