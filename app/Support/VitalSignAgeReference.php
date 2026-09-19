<?php

namespace App\Support;

/**
 * Repères des constantes selon l'âge (ADR-125) — l'unique endroit où ces
 * chiffres s'écrivent. Les évaluations (FC, tension, température) et les
 * garde-fous de saisie les lisent ici ; Vue reçoit le résultat, jamais une
 * seconde copie des tables.
 *
 * Ce sont des **aides au dépistage**, jamais un diagnostic. Elles reprennent des
 * références publiées, à faire valider par un médecin de la clinique :
 *
 *  - fréquence cardiaque de l'enfant éveillé, par tranche d'âge, et définition de
 *    l'hypotension de l'enfant — AHA, Pediatric Advanced Life Support (PALS) ;
 *  - température du nourrisson — OMS (36,5–37,5 °C chez le nouveau-né).
 *
 * L'âge est un nombre d'années entières ; 0 désigne un nourrisson de moins d'un an.
 *
 * Le **sexe** n'intervient pas : ces références ne le distinguent pas. Il compterait
 * pour l'IMC de l'enfant (courbes de croissance) et la tension de l'enfant
 * (percentiles selon âge, sexe et taille), qui exigent des tables complètes que
 * l'application ne porte pas — elle le dit plutôt que de l'inventer.
 */
final class VitalSignAgeReference
{
    public const ADULT_AGE = 18;

    /** Avant cet âge, « fume » ou « boit » est presque sûrement une faute de saisie. */
    public const SUBSTANCE_UNLIKELY_BELOW_AGE = 10;

    /** À partir de cet âge, c'est l'âge lui-même qu'il faut vérifier (date de naissance, âge déclaré). */
    public const VERY_OLD_FROM_AGE = 110;

    /** Au-delà de ces facteurs, l'écart à la plage de l'âge devient marqué. */
    public const MARKED_LOW_FACTOR = 0.85;

    public const MARKED_HIGH_FACTOR = 1.25;

    /**
     * Fréquence cardiaque de l'éveillé, en bpm : [minimum, maximum].
     *
     * @return array{0: int, 1: int}
     */
    public static function heartRateRange(int $age): array
    {
        return match (true) {
            $age < 1 => [100, 180],
            $age < 3 => [98, 140],
            $age < 6 => [80, 120],
            $age < 12 => [75, 118],
            default => [60, 100],
        };
    }

    /** Tension systolique en dessous de laquelle l'enfant est hypotendu (PALS). */
    public static function hypotensionSystolicBelow(int $age): int
    {
        return match (true) {
            $age < 1 => 70,
            $age <= 10 => 70 + 2 * $age,
            default => 90,
        };
    }

    /** « nourrisson », « enfant », « adolescent » ou « adulte ». */
    public static function band(int $age): string
    {
        return match (true) {
            $age < 1 => 'nourrisson',
            $age < 12 => 'enfant',
            $age < self::ADULT_AGE => 'adolescent',
            default => 'adulte',
        };
    }

    /**
     * Bornes d'**invraisemblance** — pas des références cliniques : très au-delà de
     * ce qu'un patient de cet âge peut peser ou mesurer, donc presque sûrement une
     * faute de saisie (un « 4 ans, 40 kg »). Volontairement larges.
     *
     * @return array{weight_max: int, height_max: int, patient_age: ?int, adult_age: int, substance_unlikely_below: int, very_old_from: int}
     */
    public static function plausibility(?int $age): array
    {
        return [
            'patient_age' => $age,
            'adult_age' => self::ADULT_AGE,
            'substance_unlikely_below' => self::SUBSTANCE_UNLIKELY_BELOW_AGE,
            'very_old_from' => self::VERY_OLD_FROM_AGE,
        ] + self::bodyLimits($age);
    }

    /** @return array{weight_max: int, height_max: int} */
    private static function bodyLimits(?int $age): array
    {
        return match (true) {
            $age === null => ['weight_max' => 300, 'height_max' => 250],
            $age < 1 => ['weight_max' => 14, 'height_max' => 85],
            $age < 2 => ['weight_max' => 18, 'height_max' => 100],
            $age < 5 => ['weight_max' => 30, 'height_max' => 130],
            $age < 10 => ['weight_max' => 70, 'height_max' => 165],
            $age < 15 => ['weight_max' => 120, 'height_max' => 200],
            default => ['weight_max' => 300, 'height_max' => 250],
        };
    }
}
