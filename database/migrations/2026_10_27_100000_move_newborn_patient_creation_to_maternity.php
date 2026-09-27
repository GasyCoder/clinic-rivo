<?php

use App\Enums\UserPermissionSource;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-177 — un bébé né à la clinique devient patient depuis la Maternité.
 *
 * La Réception n'a plus de mode « Nouveau-né » : un bébé né ailleurs est un
 * nouveau patient ordinaire, et un bébé né ici est géré là où il est consigné.
 * La création est donc désormais offerte à la Maternité, par le même droit —
 * `newborns.patient.create` — et la même action (`CreateNewbornPatientAction`).
 *
 * Sans cette migration, plus personne ne pourrait ouvrir le dossier d'un
 * nouveau-né : le droit n'était accordé qu'au socle `RECEPTION`, qui n'ouvre
 * pas l'espace Maternité. Il est recopié, en exception `ALLOW`, aux comptes qui
 * renseignent déjà la fiche du bébé (`maternity.newborn.manage`, le profil
 * sage-femme — ADR-067) : reproduire l'accès au geste, jamais l'élargir en
 * silence. Un `DENY` n'est jamais recopié ni écrasé : c'est une décision.
 *
 * Le socle `RECEPTION` n'est pas retiré d'office : sans `maternity.view`, la
 * route de la Maternité lui reste fermée, et décocher un socle reste une
 * décision du portail (ADR-064).
 */
return new class extends Migration
{
    private const LABEL = 'Ouvrir le dossier patient d’un nouveau-né depuis la Maternité';

    private const PREVIOUS_LABEL = 'Ouvrir le dossier patient d’un nouveau-né à l’accueil';

    public function up(): void
    {
        $now = now();
        $create = DB::table('permissions')->where('name', 'newborns.patient.create')->value('id');
        $manage = DB::table('permissions')->where('name', 'maternity.newborn.manage')->value('id');

        if ($create === null) {
            return;
        }

        DB::table('permissions')->where('id', $create)->update(['label' => self::LABEL, 'updated_at' => $now]);

        if ($manage !== null) {
            $refused = DB::table('user_permissions')
                ->where('permission_id', $create)
                ->pluck('user_id');

            DB::table('user_permissions')
                ->where('permission_id', $manage)
                ->where('effect', 'allow')
                ->whereNotIn('user_id', $refused)
                ->pluck('user_id')
                ->each(fn ($userId) => DB::table('user_permissions')->insertOrIgnore([
                    'user_id' => $userId,
                    'permission_id' => $create,
                    'effect' => 'allow',
                    'source' => UserPermissionSource::Manual->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

            // Le profil sage-femme le recommande désormais : un compte qualifié
            // ensuite le reçoit avec les autres droits de la Maternité (ADR-033).
            $midwife = DB::table('professional_profiles')->where('code', 'MIDWIFE')->value('id');

            if ($midwife !== null) {
                DB::table('professional_profile_permissions')->insertOrIgnore([
                    'professional_profile_id' => $midwife,
                    'permission_id' => $create,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Cache::forget(Permission::CACHE_KEY);
    }

    public function down(): void
    {
        $create = DB::table('permissions')->where('name', 'newborns.patient.create')->value('id');

        if ($create === null) {
            return;
        }

        DB::table('permissions')->where('id', $create)->update(['label' => self::PREVIOUS_LABEL, 'updated_at' => now()]);

        $midwife = DB::table('professional_profiles')->where('code', 'MIDWIFE')->value('id');

        if ($midwife !== null) {
            DB::table('professional_profile_permissions')
                ->where('professional_profile_id', $midwife)
                ->where('permission_id', $create)
                ->delete();
        }

        Cache::forget(Permission::CACHE_KEY);
    }
};
