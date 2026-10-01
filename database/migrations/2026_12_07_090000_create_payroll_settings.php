<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-233 — la paie calcule ses retenues légales selon des paramètres propres au site.
 *
 * payroll_settings   une ligne par base : CNAPS et organisme médical (taux salarié,
 *                    taux employeur, plafond), barème IRSA par tranches, IRSA minimum,
 *                    réduction par enfant, arrondi de la base. Désactivé tant que le RH
 *                    ne l'a pas relu et activé : rien n'est appliqué en silence.
 * salary_payments    garde, figés au paiement : retenues légales, charges patronales,
 *                    paramètres utilisés, mode de paiement et coordonnées.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'salary_settings.view' => 'Voir les paramètres de paie (cotisations, barème IRSA)',
        'salary_settings.update' => 'Modifier les paramètres de paie (cotisations, barème IRSA)',
        'salary_payments.export' => 'Exporter le journal de paie et la liste de virement',
    ];

    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('legal_deductions_enabled')->default(false);
            $table->decimal('cnaps_employee_rate', 5, 2)->default(0);
            $table->decimal('cnaps_employer_rate', 5, 2)->default(0);
            $table->decimal('cnaps_ceiling', 14, 2)->nullable();
            $table->string('health_label', 60)->nullable();
            $table->decimal('health_employee_rate', 5, 2)->default(0);
            $table->decimal('health_employer_rate', 5, 2)->default(0);
            $table->decimal('health_ceiling', 14, 2)->nullable();
            $table->json('irsa_brackets')->nullable();
            $table->decimal('irsa_minimum', 12, 2)->default(0);
            $table->decimal('irsa_child_reduction', 12, 2)->default(0);
            $table->unsignedInteger('irsa_base_rounding')->nullable();
            $table->boolean('allowance_subject')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name')->nullable();
            $table->timestamps();
        });

        Schema::table('salary_payments', function (Blueprint $table): void {
            $table->decimal('legal_deductions_amount', 14, 2)->default(0)->after('deductions_amount');
            $table->decimal('employer_charges_amount', 14, 2)->default(0)->after('legal_deductions_amount');
            $table->json('payroll_snapshot')->nullable()->after('lines');
            $table->string('payment_mode', 20)->nullable()->after('payroll_snapshot');
            $table->json('payment_details')->nullable()->after('payment_mode');
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
        Cache::forget(Permission::CACHE_KEY);

        Schema::table('salary_payments', function (Blueprint $table): void {
            $table->dropColumn(['legal_deductions_amount', 'employer_charges_amount', 'payroll_snapshot', 'payment_mode', 'payment_details']);
        });
        Schema::dropIfExists('payroll_settings');
    }
};
