<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-227 — avantages saisis pour les médecins et paie du mois.
 *
 * advantage_entries   un avantage saisi à la main (montant, motif, mois de paie) pour une
 *                     personne dont les avantages sont ouverts ; EN ATTENTE puis PAYÉ par
 *                     la paie du mois qui l'a porté
 * salary_payments     la paie d'un employé pour un mois : salaire de base déclaré + avantages
 *                     du mois = montant à verser, brut (aucune retenue, aucun net) ; lignes
 *                     figées ; une seule en vigueur par employé et par mois (active_key)
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'advantage_entries.view' => 'Voir les avantages saisis du personnel',
        'advantage_entries.create' => 'Saisir des avantages pour le personnel',
        'advantage_entries.update' => 'Modifier un avantage saisi non payé',
        'advantage_entries.delete' => 'Supprimer un avantage saisi non payé',
        'salary_payments.view' => 'Voir la paie du mois',
        'salary_payments.pay' => 'Marquer une paie payée (avantages du mois compris)',
        'salary_payments.cancel' => 'Annuler une paie marquée payée',
    ];

    public function up(): void
    {
        Schema::create('salary_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('employee_name');
            $table->date('period');
            $table->string('remuneration_type', 20)->nullable();
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('advantages_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->json('lines');
            $table->foreignId('advantage_award_id')->nullable()->constrained('advantage_awards')->nullOnDelete();
            $table->string('status', 20);
            $table->string('active_key')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_paid_by_uuid')->nullable();
            $table->string('external_paid_by_name')->nullable();
            $table->string('payment_note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_cancelled_by_uuid')->nullable();
            $table->string('external_cancelled_by_name')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['period', 'status']);
        });

        Schema::create('advantage_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('period');
            $table->decimal('amount', 12, 2);
            $table->string('reason', 160);
            $table->string('status', 20);
            $table->foreignId('salary_payment_id')->nullable()->constrained('salary_payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_created_by_uuid')->nullable();
            $table->string('external_created_by_name')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('delete_reason')->nullable();
            $table->index(['employee_id', 'period']);
            $table->index(['period', 'status']);
        });

        $now = now();
        $roleId = DB::table('roles')->where('code', 'ADMINISTRATION')->whereNull('deleted_at')->value('id');
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
            if ($roleId !== null) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => DB::table('permissions')->where('name', $name)->value('id'),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
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

        Schema::dropIfExists('advantage_entries');
        Schema::dropIfExists('salary_payments');

        Cache::forget(Permission::CACHE_KEY);
    }
};
