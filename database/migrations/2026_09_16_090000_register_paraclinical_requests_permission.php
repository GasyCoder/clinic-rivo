<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La porte de l'espace « Demandes d'examens » (/medicine/demandes-examens).
 *
 * L'écran réunit analyses et imagerie, mais sa route n'exigeait que
 * `laboratory_orders.view` : un compte n'ayant que l'imagerie recevait un 403
 * devant un écran que le contrôleur savait pourtant lui servir. Une route ne
 * peut exiger qu'une capacité — c'est donc une permission propre à l'écran,
 * comme `view-pharmacy-catalog` l'a été pour la page Médicaments & stock
 * (ADR-098). Les deux permissions existantes continuent de gouverner ce qu'on
 * y voit, section par section.
 *
 * Enregistrée par migration et non seulement dans `PermissionSeeder` : une
 * base déjà initialisée ne rejoue plus ce seeder (ADR-064), et sans cela
 * l'écran deviendrait inaccessible à tout le monde au premier déploiement.
 *
 * L'attribution reproduit l'accès qui existe déjà — jamais plus large :
 * quiconque pouvait ouvrir l'écran hier peut l'ouvrir demain, et personne
 * d'autre.
 */
return new class extends Migration
{
    private const NAME = 'paraclinical_requests.view';

    private const LABEL = 'Ouvrir l’espace Demandes d’examens';

    /** Les deux permissions qui donnaient l'accès jusqu'ici. */
    private const SOURCES = ['laboratory_orders.view', 'imaging_orders.view'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            [['name' => self::NAME, 'label' => self::LABEL, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['label', 'updated_at'],
        );

        $doorId = DB::table('permissions')->where('name', self::NAME)->value('id');
        $sourceIds = DB::table('permissions')->whereIn('name', self::SOURCES)->pluck('id');

        if (! $doorId || $sourceIds->isEmpty()) {
            Cache::forget(Permission::CACHE_KEY);

            return;
        }

        // Socle des rôles : tout rôle qui voyait les analyses ou l'imagerie.
        $roleIds = DB::table('role_permissions')
            ->whereIn('permission_id', $sourceIds)
            ->distinct()
            ->pluck('role_id');

        DB::table('role_permissions')->insertOrIgnore(
            $roleIds->map(fn (int $roleId): array => [
                'role_id' => $roleId,
                'permission_id' => $doorId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );

        // Exceptions individuelles : un compte dont l'accès venait d'un ALLOW
        // nominatif (ADR-022/033) le garde. Un DENY n'est jamais recopié —
        // retirer l'accès aux analyses n'a jamais voulu dire fermer l'écran,
        // et le contrôleur filtre déjà ce qu'il montre.
        $allowedUserIds = DB::table('user_permissions')
            ->whereIn('permission_id', $sourceIds)
            ->where('effect', 'allow')
            ->distinct()
            ->pluck('user_id');

        DB::table('user_permissions')->insertOrIgnore(
            $allowedUserIds->map(fn (int $userId): array => [
                'user_id' => $userId,
                'permission_id' => $doorId,
                'effect' => 'allow',
                'source' => 'MANUAL',
                'source_profile_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        Cache::forget(Permission::CACHE_KEY);
    }
};
