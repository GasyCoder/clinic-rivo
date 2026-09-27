<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-090 (amendement du 2026-09-20) — enregistrer une sortie « évadé » devient
 * un droit à part, que le Super Administrateur accorde.
 *
 * Elle relevait jusqu'ici du seul `episodes.administrative_exit` : n'importe quel
 * compte pouvant prononcer une sortie pouvait donc déclarer un patient évadé et
 * créer une créance à son nom. Comme la dette validée (`debts.authorize`), c'est
 * une décision qui engage la clinique sur un montant : elle se délègue
 * nominativement, elle ne s'hérite pas d'un droit ordinaire de Réception.
 *
 * Par défaut : ADMINISTRATION, comme `debts.authorize`. La Réception ne l'a pas
 * d'office et l'obtient par le socle de son rôle ou une exception individuelle
 * (ADR-064). Enregistrée par migration : un site en production ne rejoue plus
 * les seeders.
 */
return new class extends Migration
{
    private const NAME = 'debts.record_escape';

    private const LABEL = 'Enregistrer une sortie évadé';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [['name' => self::NAME, 'label' => self::LABEL, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionId = DB::table('permissions')->where('name', self::NAME)->value('id');

        $roles = ['ADMINISTRATION'];

        // Le portail central reçoit tout le catalogue (ADR-025, ADR-027).
        if (config('rivo.site.type') === 'admin') {
            $roles[] = 'SUPER_ADMIN';
        }

        foreach ($roles as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');

            if ($roleId) {
                DB::table('role_permissions')->insertOrIgnore([[
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                ]]);
            }
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::NAME)->value('id');

        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('user_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
