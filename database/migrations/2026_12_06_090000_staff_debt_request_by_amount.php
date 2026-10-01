<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-234 — un membre du personnel ne demande plus que le montant : il accepte les règles
 * et les conditions du site, et peut donner un motif. Le remboursement par mois et le
 * premier mois sont fixés par le DG à sa décision.
 *
 *   requested_installment, requested_first_period   facultatifs : les demandes d'avant les
 *                                                   gardent, les nouvelles n'en ont pas
 *   reason                                          facultatif
 *   terms_accepted_at, accepted_terms               ce que l'employé a accepté, en phrases,
 *                                                   et quand — ce que l'écran lui a montré
 *   engaged_at_request                              les dettes déjà en cours au moment de la
 *                                                   demande (accordées ou en remboursement)
 *
 * Une dette en cours ferme les demandes, sauf pour un compte qui a reçu du Super Admin
 * `staff_debts.request_additional` — accordé à aucun rôle par défaut.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'staff_debts.request_additional' => 'Demander une nouvelle dette alors qu’une dette est déjà en cours (autorisation du Super Admin)',
    ];

    public function up(): void
    {
        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->decimal('requested_installment', 14, 2)->nullable()->change();
            $table->date('requested_first_period')->nullable()->change();
            $table->text('reason')->nullable()->change();
            $table->timestamp('terms_accepted_at')->nullable()->after('reason');
            $table->json('accepted_terms')->nullable()->after('terms_accepted_at');
            $table->unsignedSmallInteger('engaged_at_request')->nullable()->after('accepted_terms');
        });

        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        DB::table('permissions')->where('name', 'staff_debts.request')
            ->update(['label' => 'Demander une dette depuis son compte : le montant, les règles acceptées (dettes du personnel)', 'updated_at' => $now]);

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'accepted_terms', 'engaged_at_request']);
        });

        // Les demandes faites sans mensualité ni premier mois ne reviennent pas en arrière :
        // les colonnes restent facultatives tant qu'il en existe une.
        if (! DB::table('staff_debts')->whereNull('requested_installment')->orWhereNull('requested_first_period')->orWhereNull('reason')->exists()) {
            Schema::table('staff_debts', function (Blueprint $table): void {
                $table->decimal('requested_installment', 14, 2)->nullable(false)->change();
                $table->date('requested_first_period')->nullable(false)->change();
                $table->text('reason')->nullable(false)->change();
            });
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
