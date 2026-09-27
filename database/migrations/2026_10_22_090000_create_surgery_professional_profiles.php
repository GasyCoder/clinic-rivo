<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-168 — les profils métier du rôle Chirurgie (CDC : « chirurgien, infirmier
 * de bloc, paramédical » ; l'anesthésiste est un profil du rôle NURSE, ADR-033).
 *
 * Un site en production ne rejoue plus ProfessionalProfileSeeder (ADR-064) :
 * les profils arrivent par cette migration, sur chaque site et sur le portail.
 * Aucune permission n'est recommandée — le socle SURGERY reste la seule source
 * des droits. Les comptes SURGERY existants restent sans profil (« Profil métier
 * à définir ») : aucune qualification n'est déduite.
 */
return new class extends Migration
{
    private const PROFILES = [
        'SURGEON' => [
            'name' => 'Chirurgien / Chirurgienne',
            'description' => 'Opère ; seul profil proposé comme chirurgien à la programmation du bloc.',
        ],
        'OR_NURSE' => [
            'name' => 'Infirmier / Infirmière de bloc',
            'description' => 'Instrumentation et assistance au bloc opératoire.',
        ],
        'SURGICAL_PARAMEDICAL' => [
            'name' => 'Paramédical du bloc',
            'description' => 'Personnel paramédical rattaché au bloc opératoire.',
        ],
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', 'SURGERY')->value('id');

        if (! $roleId) {
            return;
        }

        $now = now();

        foreach (self::PROFILES as $code => $definition) {
            $existing = DB::table('professional_profiles')->where('code', $code)->first();

            if ($existing) {
                continue;
            }

            DB::table('professional_profiles')->insert([
                'role_id' => $roleId,
                'code' => $code,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('professional_profiles')->whereIn('code', array_keys(self::PROFILES))->pluck('id');

        DB::table('users')->whereIn('professional_profile_id', $ids)->update(['professional_profile_id' => null]);
        DB::table('professional_profiles')->whereIn('id', $ids)->delete();
    }
};
