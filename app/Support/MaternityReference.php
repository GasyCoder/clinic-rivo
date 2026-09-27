<?php

namespace App\Support;

/**
 * Les repères que le dossier Maternité rappelle pendant la saisie (ADR-137).
 *
 * Une **aide au dépistage**, jamais un diagnostic ni un verrou : un message
 * s'affiche sous le champ, rien n'empêche d'enregistrer (hors les bornes de
 * validité de `UpdateMaternityRecordRequest`, qui sont les mêmes chiffres, lus
 * ici une seule fois). Les seuils s'écrivent à cet unique endroit et arrivent à
 * l'écran tels quels : Vue n'en recopie aucun, comme pour les constantes des
 * Soins (`VitalSignAgeReference`, ADR-125).
 *
 * Sources (références internationales courantes, **à faire valider par une
 * sage-femme ou un médecin de la clinique** avant usage clinique) :
 *
 * ```text
 * poids de naissance   OMS : faible < 2 500 g, très faible < 1 500 g,
 *                      extrêmement faible < 1 000 g ; macrosomie ≥ 4 000 g
 * Apgar                AAP / ACOG : 7-10 rassurant, 4-6 modérément bas, 0-3 bas
 * fréquence fœtale     ACOG / NICE : 110-160 bpm
 * terme                OMS : prématuré < 37 SA, grande prématurité < 28 SA ;
 *                      post-terme ≥ 42 SA
 * hauteur utérine      règle de McDonald : ≈ terme en SA, entre 20 et 36 SA
 * date prévue          règle de Naegele : dernières règles + 280 jours
 * ```
 *
 * Ce que le dossier ne fait pas, et le dit : aucun seuil n'est propre au sexe,
 * à la parité ou à une grossesse gémellaire (les poids y sont plus bas) — ces
 * nuances exigent des tables que ni le CDC ni l'application ne portent.
 */
final class MaternityReference
{
    /** Règle de datation serveur partagée avec les aperçus frontend. */
    public const PREGNANCY_TERM_DAYS = 280;

    // Bornes de validité : au-delà, `UpdateMaternityRecordRequest` refuse la saisie.
    public const BIRTH_WEIGHT_MIN_G = 100;

    public const BIRTH_WEIGHT_MAX_G = 8000;

    public const APGAR_MAX = 10;

    public const GESTATIONAL_AGE_MAX_WEEKS = 45;

    public const FUNDAL_HEIGHT_MAX_CM = 60;

    public const FETAL_HEART_RATE_MIN = 40;

    public const FETAL_HEART_RATE_MAX = 250;

    public const DILATION_MAX_CM = 10;

    /** @return array<string, array<string, int|float>> */
    public static function all(): array
    {
        return [
            'birth_weight' => [
                'min' => self::BIRTH_WEIGHT_MIN_G,
                'max' => self::BIRTH_WEIGHT_MAX_G,
                'extremely_low' => 1000,
                'very_low' => 1500,
                'low' => 2500,
                'high' => 4000,
                'very_high' => 5000,
            ],
            'apgar' => ['max' => self::APGAR_MAX, 'low_max' => 3, 'moderate_max' => 6],
            'gestational_age' => [
                'max' => self::GESTATIONAL_AGE_MAX_WEEKS,
                'extremely_preterm' => 28,
                'preterm' => 37,
                'post_term' => 42,
            ],
            'fundal_height' => [
                'max' => self::FUNDAL_HEIGHT_MAX_CM,
                'from_weeks' => 20,
                'to_weeks' => 36,
                'tolerance_cm' => 3,
            ],
            'fetal_heart_rate' => [
                'min' => self::FETAL_HEART_RATE_MIN,
                'max' => self::FETAL_HEART_RATE_MAX,
                'low' => 110,
                'very_low' => 100,
                'high' => 160,
                'very_high' => 180,
            ],
            'dilation' => ['max' => self::DILATION_MAX_CM],
            'pregnancy' => [
                'term_days' => self::PREGNANCY_TERM_DAYS,
                'implausible_days' => 300,
            ],
        ];
    }
}
