<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-216, amendement quater (2026-09-29) — le médecin valide le résultat reçu.
 *
 * Le laboratoire termine et envoie ; le médecin relit, puis valide. Ce n'est
 * qu'une fois validé que la Réception voit le résultat et son compte rendu.
 *
 *   - `approved_at` / `approved_by` sur l'analyse : la validation du médecin ;
 *   - `laboratory_results.approve` (MEDICINE) : valider un résultat reçu ;
 *   - `laboratory_results.validated_view` (RECEPTION) : voir et imprimer les
 *     résultats validés, pour les remettre au patient.
 *
 * Reprise : un résultat déjà envoyé avant cette décision a été lu et utilisé
 * par le médecin comme un résultat définitif — il est réputé validé à la date
 * de son envoi, par qui l'a envoyé. Le compter « à valider » ferait réapparaître
 * tout l'historique dans la file du médecin.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'laboratory_results.approve' => ['Valider un résultat d’analyse reçu (médecin)', 'MEDICINE'],
        'laboratory_results.validated_view' => ['Voir et imprimer les résultats d’analyses validés par le médecin', 'RECEPTION'],
    ];

    public function up(): void
    {
        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('sent_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at');
            $table->foreign('approved_by', 'lab_items_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->index('approved_at', 'lab_items_approved_at_idx');
        });

        DB::table('lab_request_items')->whereNotNull('sent_at')->where('status', 'VALIDATED')->update([
            'approved_at' => DB::raw('sent_at'),
            'approved_by' => DB::raw('validated_by'),
        ]);

        $now = now();
        foreach (self::PERMISSIONS as $name => [$label, $role]) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['label', 'updated_at'],
            );
            $permission = DB::table('permissions')->where('name', $name)->value('id');
            $roleId = DB::table('roles')->where('code', $role)->whereNull('deleted_at')->value('id');
            if ($roleId !== null) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permission, 'created_at' => $now, 'updated_at' => $now,
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

        $byColumn = DB::getDriverName() === 'sqlite';

        Schema::table('lab_request_items', function (Blueprint $table) use ($byColumn) {
            $table->dropIndex('lab_items_approved_at_idx');
            $table->dropForeign($byColumn ? ['approved_by'] : 'lab_items_approved_by_fk');
            $table->dropColumn(['approved_at', 'approved_by']);
        });

        Cache::forget(Permission::CACHE_KEY);
    }
};
