<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-245 — une dette du personnel est créée par le Super Admin, puis validée par lui.
 *
 *   staff_debts.create      créer une dette pour un employé (Super Admin du portail)
 *   staff_debts.view_own    lire ses propres dettes dans « Mes dettes » — reprise de qui
 *                           pouvait les demander, pour que personne ne perde la lecture
 *   staff_debts.request     n'est plus accordée à aucun rôle : la demande depuis son
 *                           compte reste possible, mais seulement par une décision du
 *                           Super Admin (exception nominative ou socle d'un rôle)
 *
 * external_requested_by_*   le Super Admin qui a créé la dette n'a pas de compte local.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'staff_debts.create' => 'Créer une dette pour un membre du personnel (Super Admin)',
        'staff_debts.view_own' => 'Voir ses propres dettes (Mes dettes)',
    ];

    public function up(): void
    {
        // Rejouable : sur MySQL, un essai interrompu laisse les colonnes déjà ajoutées.
        if (! Schema::hasColumn('staff_debts', 'external_requested_by_uuid')) {
            Schema::table('staff_debts', function (Blueprint $table): void {
                $table->uuid('external_requested_by_uuid')->nullable()->after('requested_by');
                $table->string('external_requested_by_name')->nullable()->after('external_requested_by_uuid');
            });
        }

        $now = now();
        foreach (self::PERMISSIONS as $name => $label) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
        }

        $request = DB::table('permissions')->where('name', 'staff_debts.request')->value('id');
        $viewOwn = DB::table('permissions')->where('name', 'staff_debts.view_own')->value('id');

        if ($request !== null && $viewOwn !== null) {
            // Qui lisait ses dettes les lit toujours : le socle des rôles, et les ALLOW nominatifs.
            foreach (DB::table('role_permissions')->where('permission_id', $request)->pluck('role_id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $viewOwn, 'created_at' => $now, 'updated_at' => $now]);
            }

            foreach (DB::table('user_permissions')->where('permission_id', $request)->where('effect', 'allow')->get() as $row) {
                DB::table('user_permissions')->insertOrIgnore([
                    ...collect((array) $row)->except(['id', 'permission_id', 'created_at', 'updated_at'])->all(),
                    'permission_id' => $viewOwn,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // La demande depuis son compte quitte le socle des rôles ; le Super Admin la rouvre s'il le décide.
            DB::table('role_permissions')
                ->where('permission_id', $request)
                ->whereNotIn('role_id', DB::table('roles')->where('code', 'SUPER_ADMIN')->select('id'))
                ->delete();

            DB::table('permissions')->where('id', $request)
                ->update(['label' => 'Demander une dette depuis son compte (fermé par défaut : seul le Super Admin crée une dette)', 'updated_at' => $now]);
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('staff_debts', function (Blueprint $table): void {
            $table->dropColumn(['external_requested_by_uuid', 'external_requested_by_name']);
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
