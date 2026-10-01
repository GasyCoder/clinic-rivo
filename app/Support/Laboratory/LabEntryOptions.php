<?php

namespace App\Support\Laboratory;

/**
 * ADR-213 — les réponses fixes de la paillasse, écrites une seule fois pour le
 * serveur (validation, résumé imprimé) et pour l'écran (props).
 *
 * Culture : les issues du laboratoire historique (labo-vuejs) — non recherchée,
 * en cours, stérile, absence de germe pathogène, croissance (germes identifiés,
 * chacun avec son antibiogramme), autre (à préciser).
 *
 * Nugent : le score standard de la flore vaginale, somme de trois
 * sous-scores (lactobacilles 0–4, Gardnerella/Bacteroides 0–4, Mobiluncus
 * 0–2). Lecture usuelle : 0–3 flore normale, 4–6 flore intermédiaire,
 * 7–10 vaginose bactérienne.
 */
final class LabEntryOptions
{
    public const NEGATIVE = 'Négatif';

    public const POSITIVE = 'Positif';

    public const ABSENT = 'Absence';

    public const PRESENT = 'Présence';

    public const CULTURE_GROWTH = 'GROWTH';

    public const CULTURE_OTHER = 'OTHER';

    public const CULTURE = [
        'NOT_SEARCHED' => 'Non recherchée',
        'IN_PROGRESS' => 'Culture en cours',
        'STERILE' => 'Culture stérile',
        'NO_PATHOGEN' => 'Absence de germe pathogène',
        self::CULTURE_GROWTH => 'Présence de germe(s)',
        self::CULTURE_OTHER => 'Autre',
    ];

    public const NUGENT_PARTS = [
        'lactobacilli' => ['label' => 'Lactobacilles', 'max' => 4],
        'gardnerella' => ['label' => 'Gardnerella / Bacteroides', 'max' => 4],
        'mobiluncus' => ['label' => 'Mobiluncus', 'max' => 2],
    ];

    /** @return array{label: string, suggested: ?string} */
    public static function nugent(int $score): array
    {
        return match (true) {
            $score <= 3 => ['label' => 'Flore normale', 'suggested' => 'NORMAL'],
            $score <= 6 => ['label' => 'Flore intermédiaire', 'suggested' => null],
            default => ['label' => 'Vaginose bactérienne', 'suggested' => 'PATHOLOGICAL'],
        };
    }

    /** @return array<string, mixed> ce que l'écran reçoit */
    public static function forScreen(): array
    {
        return [
            'negative_positive' => [self::NEGATIVE, self::POSITIVE],
            'absence_presence' => [self::ABSENT, self::PRESENT],
            'culture' => collect(self::CULTURE)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'nugent_parts' => collect(self::NUGENT_PARTS)->map(fn (array $part, string $key) => ['key' => $key, ...$part])->values()->all(),
            'interpretations' => [
                ['value' => 'NORMAL', 'label' => 'Normal'],
                ['value' => 'PATHOLOGICAL', 'label' => 'Pathologique'],
            ],
            'antibiogram' => [
                ['value' => 'S', 'label' => 'Sensible'],
                ['value' => 'I', 'label' => 'Intermédiaire'],
                ['value' => 'R', 'label' => 'Résistant'],
            ],
        ];
    }
}
