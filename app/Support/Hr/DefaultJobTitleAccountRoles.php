<?php

namespace App\Support\Hr;

use App\Enums\HrReferenceType;
use App\Models\HrReferenceValue;

/**
 * ADR-199 — la proposition de départ : le rôle, et s'il le faut le profil
 * métier, que chaque fonction livrée propose au compte de celui qui l'exerce.
 *
 * Lue sur le CDC §9 (« Fonctions internes » : laborantin → Laboratoire,
 * pharmacien → Pharmacie…), affinée par l'ADR-033 (infirmier, sage-femme et
 * anesthésiste sont des profils du rôle Soins ; gardien et agent d'entretien
 * des profils de Support) et par l'ADR-168 (l'infirmier de bloc est un profil
 * de Chirurgie).
 *
 * Une fonction que le CDC ne rattache à aucun rôle n'en reçoit pas : Gérant,
 * Jardinier, Chauffeur, Maintenance, Lingerie, Serveur, Dentiste, Assistant
 * Dentisterie. Leur compte se règle à la main, ou dans le module Fonctions.
 *
 * Elle ne touche qu'une fonction sur laquelle rien n'a encore été décidé : un
 * réglage de la clinique — même « aucun rôle proposé » — n'est jamais réécrit,
 * et la rejouer (migration, seeder) ne change rien.
 */
final class DefaultJobTitleAccountRoles
{
    /** @var array<string, array{0: string, 1: string|null}> code de fonction => [rôle, profil] */
    public const MAP = [
        'ADMIN' => ['ADMINISTRATION', null],
        'DOCTOR' => ['MEDICINE', null],
        'GENERAL_NURSE' => ['NURSE', 'REGISTERED_NURSE'],
        'MIDWIFE' => ['NURSE', 'MIDWIFE'],
        'ANESTHETIST_NURSE' => ['NURSE', 'ANESTHETIST'],
        'OPERATING_ROOM_NURSE' => ['SURGERY', 'OR_NURSE'],
        'LAB_TECHNICIAN' => ['LABORATORY', null],
        'PHARMACIST' => ['PHARMACY', null],
        'GUARD' => ['SUPPORT', 'GUARD'],
        'HOUSEKEEPER' => ['SUPPORT', 'CLEANER'],
    ];

    /** Règle les fonctions encore sans décision ; renvoie le nombre de fonctions réglées. */
    public static function apply(): int
    {
        $applied = 0;

        HrReferenceValue::withTrashed()
            ->ofType(HrReferenceType::JobTitle)
            ->whereIn('code', array_keys(self::MAP))
            ->get()
            ->each(function (HrReferenceValue $jobTitle) use (&$applied): void {
                $metadata = $jobTitle->metadata ?? [];

                if (array_key_exists(JobTitleAccountRole::ROLE_KEY, $metadata)) {
                    return;
                }

                [$role, $profile] = self::MAP[$jobTitle->code];
                $jobTitle->metadata = [
                    ...$metadata,
                    JobTitleAccountRole::ROLE_KEY => $role,
                    JobTitleAccountRole::PROFILE_KEY => $profile,
                ];
                $jobTitle->saveQuietly();
                $applied++;
            });

        return $applied;
    }
}
