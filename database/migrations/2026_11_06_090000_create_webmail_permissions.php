<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-194 (amendement du 2026-09-25) — la messagerie dépend des permissions :
 *
 *  - `webmail.view`     : la messagerie apparaît, et le compte ouvre sa propre boîte.
 *                          Accordée aux rôles opérationnels des sites — sauf SUPPORT et
 *                          MAINTENANCE, sans droit par défaut (ADR-033) ;
 *  - `webmail.open_any` : ouvrir la boîte d'un autre employé. Accordée à aucun rôle
 *                          d'un site ; le Super Administrateur du portail la reçoit,
 *                          comme toutes les permissions (ADR-186).
 *
 * Un site en production ne rejoue pas RolePermissionSeeder (ADR-064) : c'est cette
 * migration qui enregistre les deux droits et le socle de départ.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'webmail.view' => 'Utiliser la messagerie professionnelle (ouvrir sa propre boîte)',
        'webmail.open_any' => 'Ouvrir la boîte professionnelle d’un autre employé (avec son mot de passe)',
    ];

    private const VIEW_ROLES = ['ADMINISTRATION', 'LOGISTICS', 'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])->values()->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        // Un site : le socle de départ. Le portail : le Super Administrateur reçoit
        // tout par la synchronisation de l'ADR-186, qui suit chaque `migrate`.
        if (config('rivo.site.type') !== 'admin') {
            $viewId = DB::table('permissions')->where('name', 'webmail.view')->value('id');

            foreach (DB::table('roles')->whereIn('code', self::VIEW_ROLES)->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $viewId, 'created_at' => $now, 'updated_at' => $now]);
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
    }
};
