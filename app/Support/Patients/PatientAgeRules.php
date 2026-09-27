<?php

namespace App\Support\Patients;

use App\Enums\PatientAgeBand;
use App\Enums\PatientCivility;

/**
 * Ce que l'âge impose au formulaire d'un nouveau patient (ADR-184), écrit une
 * fois pour la validation serveur ; l'écran applique les mêmes bornes, reçues
 * du site, mais c'est ici que le refus se décide.
 *
 *  - un bébé se déclare avec sa date de naissance exacte : « 0 an » ou « 1 an »
 *    ne dit pas s'il a trois semaines ou vingt-trois mois ;
 *  - un bébé ou un enfant porte la civilité « Enfant fille / garçon », un adulte
 *    jamais — une civilité qui contredit l'âge est refusée, pas corrigée en
 *    silence.
 */
final class PatientAgeRules
{
    /**
     * @param  array{baby_max_age: int, child_max_age: int}  $bands
     */
    public static function band(mixed $birthDate, mixed $age, array $bands): ?PatientAgeBand
    {
        $years = PatientAgeBand::yearsFromBirthDate($birthDate);

        if ($years === null && is_numeric($age) && (int) $age >= 0) {
            $years = (int) $age;
        }

        return PatientAgeBand::forYears($years, $bands);
    }

    /**
     * @param  array{baby_max_age: int, child_max_age: int}  $bands
     * @return array<string, string> champ => message
     */
    public static function violations(mixed $birthDate, mixed $age, mixed $civility, array $bands): array
    {
        $band = self::band($birthDate, $age, $bands);

        if ($band === null) {
            return [];
        }

        $violations = [];
        $babyUpTo = self::years($bands['baby_max_age']);
        $childUpTo = self::years($bands['child_max_age']);
        $adultFrom = self::years($bands['child_max_age'] + 1);

        $declaredAgeOnly = PatientAgeBand::yearsFromBirthDate($birthDate) === null;

        if ($band === PatientAgeBand::Baby && $declaredAgeOnly) {
            $violations['birth_date'] = "Pour un bébé (jusqu’à {$babyUpTo}), saisissez la date de naissance exacte : un âge en années ne suffit pas.";
        }

        $childCivility = in_array($civility, [PatientCivility::Girl->value, PatientCivility::Boy->value], true);
        $adultCivility = in_array($civility, [PatientCivility::Mr->value, PatientCivility::Mrs->value], true);

        if ($band === PatientAgeBand::Adult && $childCivility) {
            $violations['civility'] = "La civilité « Enfant » ne convient pas à un adulte ({$adultFrom} et plus) : choisissez M. ou Mme.";
        }

        if ($band->isMinor() && $adultCivility) {
            $who = $band === PatientAgeBand::Baby ? "un bébé (jusqu’à {$babyUpTo})" : "un enfant (jusqu’à {$childUpTo})";
            $violations['civility'] = "Ce patient est {$who} : choisissez « Enfant fille » ou « Enfant garçon ».";
        }

        return $violations;
    }

    private static function years(int $value): string
    {
        return $value.' an'.($value > 1 ? 's' : '');
    }
}
