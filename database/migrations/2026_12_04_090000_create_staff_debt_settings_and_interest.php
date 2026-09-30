<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-229 — les dettes du personnel passent dans Finance, au portail : chaque site
 * reçoit ses réglages (montants, durée, part du salaire, dettes en cours, ancienneté,
 * stagiaires, ouverture des demandes, tranches d'intérêt), et chaque dette l'intérêt
 * figé à sa décision, les dérogations accordées par le DG et la trace de ses relances.
 *
 * Aucune ligne de réglage n'est créée : sans elle, aucune limite et aucun intérêt,
 * comme avant. Les dettes existantes gardent un intérêt nul.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'staff_debts.settings' => 'Régler les dettes du personnel : montants, intérêts, limites, ouverture des demandes',
        'staff_debts.export' => 'Exporter en Excel les dettes du personnel',
    ];

    public function up(): void
    {
        Schema::create('staff_debt_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('requests_open')->default(true);
            $table->string('closed_message', 500)->nullable();
            $table->decimal('min_amount', 14, 2)->nullable();
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('max_months')->nullable();
            $table->unsignedTinyInteger('max_salary_share')->nullable();
            $table->unsignedTinyInteger('max_open_debts')->nullable();
            $table->unsignedSmallInteger('min_seniority_months')->nullable();
            $table->boolean('exclude_interns')->default(false);
            $table->json('interest_tiers')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('external_updated_by_uuid')->nullable();
            $table->string('external_updated_by_name')->nullable();
            $table->timestamps();
        });

        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->decimal('requested_interest_amount', 14, 2)->default(0)->after('requested_first_period');
            $table->decimal('interest_amount', 14, 2)->default(0)->after('installment_amount');
            $table->string('interest_mode', 10)->nullable()->after('interest_amount');
            $table->decimal('interest_value', 14, 2)->nullable()->after('interest_mode');
            $table->boolean('interest_waived')->default(false)->after('interest_value');
            $table->json('derogations')->nullable()->after('decision_note');
            $table->string('arrears_notified_for', 7)->nullable()->after('settled_at');
        });

        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        // Tout se décide et se verse au portail : le RH du site ne verse plus.
        $disburse = DB::table('permissions')->where('name', 'staff_debts.disburse')->value('id');
        $administration = DB::table('roles')->where('code', 'ADMINISTRATION')->value('id');
        if ($disburse !== null && $administration !== null) {
            DB::table('role_permissions')->where('role_id', $administration)->where('permission_id', $disburse)->delete();
        }

        DB::table('permissions')->where('name', 'staff_debts.view')->update(['label' => 'Voir les dettes du personnel (Finance, et relances des retards sur le site)', 'updated_at' => $now]);

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->dropColumn(['requested_interest_amount', 'interest_amount', 'interest_mode', 'interest_value', 'interest_waived', 'derogations', 'arrears_notified_for']);
        });
        Schema::dropIfExists('staff_debt_settings');

        Cache::forget(Permission::CACHE_KEY);
    }
};
