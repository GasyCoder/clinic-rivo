<?php

use App\Enums\UserPermissionSource;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-146 (amendement) — le nouveau-né a ses propres droits.
 *
 * Le dossier d'un bébé qui n'est pas encore patient se lit sur la fiche de sa mère, et cette lecture
 * exigeait `maternity.view` : le droit de l'espace Maternité, qu'aucun rôle ne porte et que seul le
 * profil sage-femme reçoit en exception individuelle (ADR-067). La Réception, qui accueille l'enfant,
 * et Médecine, qui le soigne, ne pouvaient donc pas ouvrir son dossier depuis celui de sa mère — le
 * bouton n'était même pas affiché.
 *
 * Trois droits nomment désormais les trois gestes réels, identité et clinique séparées :
 *
 *   newborns.view                  l'enfant : nom, rang, sexe, date de naissance, s'il est patient
 *   newborns.medical_record.view   son dossier : poids, Apgar, état, soins, mode d'accouchement
 *   newborns.patient.create        en faire un patient, à l'accueil
 *
 * Chacun est réellement vérifié par le code (ADR-101) : une permission que rien ne contrôle est un
 * interrupteur qui ne commande rien.
 *
 * `RolePermissionSeeder` porte ces attributions pour la création d'un site neuf ; un site déjà en
 * production ne le rejoue plus (ADR-064), d'où cette migration. Elle ne touche que les socles de rôle
 * et les exceptions `ALLOW` existantes — jamais un `DENY`, qui reste une décision.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'newborns.view' => 'Voir les nouveau-nés d’une mère : nom, rang, sexe et date de naissance',
        'newborns.medical_record.view' => 'Ouvrir le dossier médical d’un nouveau-né : naissance, poids, Apgar, état et soins',
        'newborns.patient.create' => 'Ouvrir le dossier patient d’un nouveau-né à l’accueil',
    ];

    /**
     * Le socle de départ, identique à `RolePermissionSeeder::GRANTS`.
     *
     * `newborns.medical_record.view` n'est volontairement pas accordé à `RECEPTION` : le poids,
     * l'Apgar et le mode d'accouchement sont cliniques. Le Super Administrateur l'accorde depuis
     * « Rôles & permissions » s'il le décide, et cette décision est alors tracée — au lieu d'être
     * prise ici, une fois, pour tous les sites.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'RECEPTION' => ['newborns.view', 'newborns.patient.create'],
        'MEDICINE' => ['newborns.view', 'newborns.medical_record.view'],
        'NURSE' => ['newborns.view', 'newborns.medical_record.view'],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            collect(self::PERMISSIONS)
                ->map(fn (string $label, string $name): array => compact('name', 'label') + ['created_at' => $now, 'updated_at' => $now])
                ->values()
                ->all(),
            ['name'],
            ['label', 'updated_at'],
        );

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id', 'name');

        foreach (self::GRANTS as $code => $permissions) {
            $roleId = DB::table('roles')->where('code', $code)->whereNull('deleted_at')->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('role_permissions')->insertOrIgnore(
                collect($permissions)->map(fn (string $permission): array => [
                    'role_id' => $roleId,
                    'permission_id' => $ids[$permission],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }

        // Un compte qui lisait déjà ce dossier par une exception individuelle `maternity.view` — le
        // profil sage-femme — continue de le lire : reproduire l'accès existant, jamais l'élargir en
        // silence (même principe que l'ADR-100 pour `paraclinical_requests.view`). Un `DENY` n'est pas
        // recopié : refuser l'espace Maternité n'a jamais voulu dire refuser la naissance d'un enfant.
        $maternityView = DB::table('permissions')->where('name', 'maternity.view')->value('id');

        if ($maternityView !== null) {
            $allowed = DB::table('user_permissions')
                ->where('permission_id', $maternityView)
                ->where('effect', 'allow')
                ->pluck('user_id');

            foreach ($allowed as $userId) {
                DB::table('user_permissions')->insertOrIgnore(
                    collect(['newborns.view', 'newborns.medical_record.view'])
                        ->map(fn (string $permission): array => [
                            'user_id' => $userId,
                            'permission_id' => $ids[$permission],
                            'effect' => 'allow',
                            'source' => UserPermissionSource::Manual->value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all(),
                );
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
