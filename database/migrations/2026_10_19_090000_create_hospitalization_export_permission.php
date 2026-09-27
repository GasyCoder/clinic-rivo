<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-165 — exporter la liste des patients hospitalisés est un droit à part.
 *
 * La liste porte des données personnelles (nom, âge, motif) : la voir ne suffit
 * pas à l'emporter hors de l'application, même logique que `patients.export`
 * (ADR-133). Accordée par défaut à Médecine et Soins ; la Réception, qui lit le
 * module (ADR-147), ne la reçoit pas. Un site en production ne rejoue plus
 * `RolePermissionSeeder` (ADR-064), d'où cette migration.
 */
return new class extends Migration
{
    private const PERMISSION = 'hospitalization.export';

    private const LABEL = 'Exporter en Excel la liste des patients hospitalisés';

    /** @var list<string> */
    private const ROLES = ['MEDICINE', 'NURSE'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [['name' => self::PERMISSION, 'label' => self::LABEL, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['label', 'updated_at'],
        );

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        DB::table('roles')->whereIn('code', self::ROLES)->whereNull('deleted_at')->pluck('id')
            ->each(fn (int $roleId) => DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        DB::table('user_permissions')->where('permission_id', $id)->delete();
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();

        Cache::forget(Permission::CACHE_KEY);
    }
};
