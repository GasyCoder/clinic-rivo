<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-228 — les dettes du personnel.
 *
 * staff_debts             une dette : demandée par l'employé depuis son compte, décidée par le
 *                         DG (accordée, ajustée ou refusée), versée hors RIVO puis remboursée
 *                         par retenue sur la paie du mois ou en espèces à la Caisse. Jamais
 *                         supprimée : refusée, annulée, soldée ou remise, avec son motif.
 * staff_debt_repayments   chaque remboursement : retenue d'une paie (salary_payment_id) ou
 *                         encaissement à la Caisse (cash_movement_id, reçu). Jamais supprimé :
 *                         annulé avec un motif (paie annulée, erreur de caisse).
 * staff_debt_notices      portail : une demande n'est signalée qu'une fois au DG.
 * salary_payments         la paie garde désormais ses retenues : à verser = brut − retenues.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'staff_debts.request' => 'Demander une dette depuis son compte (dettes du personnel)',
        'staff_debts.view' => 'Voir les dettes du personnel',
        'staff_debts.decide' => 'Accorder, ajuster, refuser ou annuler une dette du personnel (DG)',
        'staff_debts.write_off' => 'Remettre le reste d’une dette du personnel (DG)',
        'staff_debts.disburse' => 'Marquer versée une dette accordée au personnel',
        'staff_debts.collect' => 'Encaisser à la Caisse le remboursement d’une dette du personnel',
    ];

    /** Qui reçoit quoi par défaut ; le DG (SUPER_ADMIN du portail) reçoit tout par l'ADR-186. */
    private const GRANTS = [
        'staff_debts.request' => ['ADMINISTRATION', 'LOGISTICS', 'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'],
        'staff_debts.view' => ['ADMINISTRATION'],
        'staff_debts.disburse' => ['ADMINISTRATION'],
        'staff_debts.collect' => ['RECEPTION'],
    ];

    public function up(): void
    {
        Schema::create('staff_debts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 40)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('employee_name');
            $table->string('employee_number', 60)->nullable();

            // La demande, telle que l'employé l'a faite.
            $table->decimal('requested_amount', 14, 2);
            $table->decimal('requested_installment', 14, 2);
            $table->date('requested_first_period');
            $table->text('reason');
            $table->timestamp('requested_at');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->index();
            // Une seule demande en attente par employé.
            $table->string('pending_key')->nullable()->unique();

            // Ce que le DG a accordé (et ajusté depuis).
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('installment_amount', 14, 2)->nullable();
            $table->date('first_period')->nullable();
            $table->string('repayment_mode', 20)->nullable();
            $table->text('decision_note')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_decided_by_uuid')->nullable();
            $table->string('external_decided_by_name')->nullable();

            // Le versement, fait hors RIVO et constaté ici.
            $table->date('disbursed_on')->nullable();
            $table->string('disbursement_mode', 20)->nullable();
            $table->string('disbursement_reference', 120)->nullable();
            $table->string('disbursement_note', 500)->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_disbursed_by_uuid')->nullable();
            $table->string('external_disbursed_by_name')->nullable();

            $table->timestamp('settled_at')->nullable();

            $table->decimal('written_off_amount', 14, 2)->default(0);
            $table->text('write_off_reason')->nullable();
            $table->timestamp('written_off_at')->nullable();
            $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_written_off_by_uuid')->nullable();
            $table->string('external_written_off_by_name')->nullable();

            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_cancelled_by_uuid')->nullable();
            $table->string('external_cancelled_by_name')->nullable();

            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('staff_debt_repayments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('staff_debt_id')->constrained('staff_debts')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('source', 20);
            $table->date('period');
            $table->decimal('amount', 14, 2);
            $table->foreignId('salary_payment_id')->nullable()->constrained('salary_payments')->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->string('receipt_number', 40)->nullable()->unique();
            $table->string('note', 500)->nullable();
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_recorded_by_uuid')->nullable();
            $table->string('external_recorded_by_name')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_reversed_by_uuid')->nullable();
            $table->string('external_reversed_by_name')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->foreignId('reversal_cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['staff_debt_id', 'reversed_at'], 'staff_debt_repayments_debt_reversed_index');
            $table->index(['employee_id', 'period'], 'staff_debt_repayments_employee_period_index');
        });

        Schema::create('staff_debt_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('site_code', 20);
            $table->uuid('debt_uuid');
            $table->timestamp('noticed_at');
            $table->timestamps();
            $table->unique(['site_code', 'debt_uuid']);
        });

        Schema::table('salary_payments', function (Blueprint $table): void {
            $table->decimal('deductions_amount', 14, 2)->default(0)->after('advantages_amount');
        });

        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        foreach (self::GRANTS as $name => $roles) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            foreach ($roles as $code) {
                $roleId = DB::table('roles')->where('code', $code)->whereNull('deleted_at')->value('id');
                if ($roleId !== null && $permissionId !== null) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('salary_payments', function (Blueprint $table): void {
            $table->dropColumn('deductions_amount');
        });
        Schema::dropIfExists('staff_debt_notices');
        Schema::dropIfExists('staff_debt_repayments');
        Schema::dropIfExists('staff_debts');

        Cache::forget(Permission::CACHE_KEY);
    }
};
