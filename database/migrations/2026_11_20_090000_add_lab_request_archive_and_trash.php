<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-220 — les demandes d'analyses se rangent, se corrigent et se mettent à la corbeille.
 *
 *   lab_archived_at / by   « Archiver » : ranger une demande dont tout est envoyé au
 *                          médecin. Réversible, sans motif. Distinct de `archived_at`
 *                          (ADR-131), qui range la demande dans « Demandes d'examens »
 *                          du médecin : ranger d'un côté ne range pas de l'autre.
 *   deleted_at / by / delete_reason
 *                          « Mettre à la corbeille » (ADR-009) : une demande saisie à
 *                          tort, jamais après un envoi au médecin. Restaurable,
 *                          jamais détruite (ADR-010).
 *
 * Une analyse retirée d'une demande est, elle aussi, mise à la corbeille de la
 * demande (même colonnes, sur `lab_request_items`) : elle quitte toutes les listes,
 * sa trace reste en base et dans l'audit.
 *
 * Droits (ADR-064 : un site en production ne rejoue plus le seeder) :
 *   laboratory_orders.update / .archive   au rôle LABORATORY
 *   laboratory_orders.delete / .restore   à aucun rôle d'un site — le Super Admin du
 *                                         portail les reçoit (ADR-186) et les accorde
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'laboratory_orders.update' => 'Modifier une demande d’analyses (ajouter ou retirer une analyse, renseignements)',
        'laboratory_orders.archive' => 'Archiver ou désarchiver une demande d’analyses terminée',
        'laboratory_orders.delete' => 'Mettre une demande d’analyses à la corbeille (jamais après un envoi)',
        'laboratory_orders.restore' => 'Restaurer une demande d’analyses depuis la corbeille',
    ];

    private const GRANTS = [
        'LABORATORY' => ['laboratory_orders.update', 'laboratory_orders.archive'],
    ];

    public function up(): void
    {
        Schema::table('lab_requests', function (Blueprint $table) {
            $table->timestamp('lab_archived_at')->nullable()->after('archived_by');
            $table->foreignId('lab_archived_by')->nullable()->after('lab_archived_at');
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at');
            $table->text('delete_reason')->nullable()->after('deleted_by');

            // Nommées à la main : MySQL refuse un identifiant de plus de 64 caractères.
            $table->foreign('lab_archived_by', 'lab_requests_lab_archived_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('deleted_by', 'lab_requests_deleted_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index('lab_archived_at', 'lab_requests_lab_archived_idx');
        });

        Schema::table('lab_request_items', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at');
            $table->text('delete_reason')->nullable()->after('deleted_by');

            $table->foreign('deleted_by', 'lab_request_items_deleted_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        foreach (self::GRANTS as $code => $names) {
            $permissionIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

            DB::table('roles')->where('code', $code)->whereNull('deleted_at')->pluck('id')
                ->each(fn (int $roleId) => $permissionIds->each(fn (int $permissionId) => DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])));
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        Schema::table('lab_request_items', function (Blueprint $table) use ($sqlite) {
            $sqlite ? $table->dropForeign(['deleted_by']) : $table->dropForeign('lab_request_items_deleted_by_fk');
            $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
        });

        Schema::table('lab_requests', function (Blueprint $table) use ($sqlite) {
            if ($sqlite) {
                $table->dropForeign(['lab_archived_by']);
                $table->dropForeign(['deleted_by']);
            } else {
                $table->dropForeign('lab_requests_lab_archived_by_fk');
                $table->dropForeign('lab_requests_deleted_by_fk');
            }
            $table->dropIndex('lab_requests_lab_archived_idx');
            $table->dropColumn(['lab_archived_at', 'lab_archived_by', 'deleted_at', 'deleted_by', 'delete_reason']);
        });

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('user_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Cache::forget(Permission::CACHE_KEY);
    }
};
