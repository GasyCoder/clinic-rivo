<?php

use App\Enums\UserPermissionSource;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-216, amendement du 2026-09-29 — « Renvoyer à refaire » a son propre droit.
 *
 * Il suivait jusqu'ici `laboratory_results.create` (analyse terminée) ou
 * `laboratory_results.validate` (analyse envoyée) : impossible à accorder ou à
 * retirer seul depuis « Rôles & permissions ». Le nouveau droit reproduit l'accès
 * existant, jamais plus large : les rôles qui détenaient l'un des deux le
 * reçoivent, et les comptes qui l'avaient par une exception ALLOW aussi. Un DENY
 * n'est pas recopié — refuser la saisie n'a jamais voulu dire refuser la reprise.
 */
return new class extends Migration
{
    private const NAME = 'laboratory_results.return';

    private const LABEL = 'Renvoyer une analyse à refaire (terminée ou déjà envoyée au médecin)';

    private const PREVIOUS = ['laboratory_results.create', 'laboratory_results.validate'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [['name' => self::NAME, 'label' => self::LABEL, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['label', 'updated_at'],
        );

        $id = DB::table('permissions')->where('name', self::NAME)->value('id');
        $previous = DB::table('permissions')->whereIn('name', self::PREVIOUS)->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $previous)->distinct()->pluck('role_id')
            ->each(fn (int $roleId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        DB::table('user_permissions')->whereIn('permission_id', $previous)->where('effect', 'allow')
            ->distinct()->pluck('user_id')
            ->each(fn (int $userId) => DB::table('user_permissions')->insertOrIgnore([
                'user_id' => $userId,
                'permission_id' => $id,
                'effect' => 'allow',
                'source' => UserPermissionSource::Manual->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::NAME)->value('id');

        if ($id !== null) {
            DB::table('user_permissions')->where('permission_id', $id)->delete();
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
