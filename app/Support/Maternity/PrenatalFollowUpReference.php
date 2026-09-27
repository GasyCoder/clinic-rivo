<?php

namespace App\Support\Maternity;

/**
 * ADR-204 — les rappels d'examens du suivi prénatal, **proposés, jamais
 * imposés**.
 *
 * Ce tableau est une aide de saisie et d'organisation : il dit ce qu'il est
 * habituel de demander à une période de la grossesse. Il ne crée aucune
 * demande, ne pose aucun diagnostic, ne prescrit rien et n'empêche jamais de
 * terminer une consultation.
 *
 * **À valider par la Clinique Saint Georges.** Le document de référence cité
 * par le propriétaire (« Suivi de grossesse normale 2021 ») n'a pas été
 * transmis avec la demande : le contenu ci-dessous reprend les repères de suivi
 * prénatal les plus couramment publiés (trois échographies, bilan du premier
 * contact) et reste une **proposition**, marquée comme telle à l'écran
 * (`VALIDATED = false`). Le corriger, c'est corriger ce seul fichier.
 *
 * Chaque examen désigne un **code** du catalogue du site, jamais un libellé
 * (ADR-052) : un code absent du catalogue d'un site est affiché sans bouton
 * « Demander », jamais remplacé par un autre examen.
 */
final class PrenatalFollowUpReference
{
    /** Tant que la clinique ne l'a pas validé, l'écran le dit. */
    public const VALIDATED = false;

    public const NOTICE = 'Rappels indicatifs — proposition à valider par la clinique. Ils ne créent aucune demande et ne bloquent rien.';

    /**
     * Les périodes, en semaines d'aménorrhée révolues (bornes incluses), et
     * les examens proposés. `from`/`to` d'un examen disent sa fenêtre
     * habituelle ; `null` = au premier contact de la période.
     *
     * @var list<array{key: string, label: string, from: int, to: int, items: list<array{category: string, code: string, label: string, from: ?int, to: ?int}>}>
     */
    public const PERIODS = [
        [
            'key' => 'FIRST_TRIMESTER',
            'label' => '1er trimestre',
            'from' => 0,
            'to' => 13,
            'items' => [
                ['category' => 'LAB', 'code' => 'LAB-GROUP-RH', 'label' => 'Groupage sanguin et rhésus', 'from' => null, 'to' => null],
                ['category' => 'LAB', 'code' => 'LAB-SYPHILIS', 'label' => 'Sérologie syphilitique', 'from' => null, 'to' => null],
                ['category' => 'LAB', 'code' => 'LAB-VIH', 'label' => 'Sérologie VIH', 'from' => null, 'to' => null],
                ['category' => 'LAB', 'code' => 'LAB-AGHBS', 'label' => 'Antigène HBs', 'from' => null, 'to' => null],
                ['category' => 'LAB', 'code' => 'LAB-NFS', 'label' => 'Numération formule sanguine', 'from' => null, 'to' => null],
                ['category' => 'LAB', 'code' => 'LAB-GLYC', 'label' => 'Glycémie', 'from' => null, 'to' => null],
                ['category' => 'IMAGING', 'code' => 'ECHO-OBS-T1', 'label' => 'Échographie du 1er trimestre', 'from' => 11, 'to' => 13],
            ],
        ],
        [
            'key' => 'SECOND_TRIMESTER',
            'label' => '2e trimestre',
            'from' => 14,
            'to' => 27,
            'items' => [
                ['category' => 'IMAGING', 'code' => 'ECHO-MORPHO', 'label' => 'Échographie morphologique', 'from' => 20, 'to' => 24],
                ['category' => 'LAB', 'code' => 'LAB-NFS', 'label' => 'Numération formule sanguine', 'from' => 24, 'to' => 27],
            ],
        ],
        [
            'key' => 'THIRD_TRIMESTER',
            'label' => '3e trimestre',
            'from' => 28,
            'to' => 45,
            'items' => [
                ['category' => 'IMAGING', 'code' => 'ECHO-OBS-T3', 'label' => 'Échographie du 3e trimestre', 'from' => 30, 'to' => 34],
            ],
        ],
    ];

    /** @return array{key: string, label: string, from: int, to: int, items: list<array<string, mixed>>}|null */
    public static function periodFor(?int $weeks): ?array
    {
        if ($weeks === null) {
            return null;
        }

        foreach (self::PERIODS as $period) {
            if ($weeks >= $period['from'] && $weeks <= $period['to']) {
                return $period;
            }
        }

        return null;
    }

    /** « 11 à 13 SA + 6 j », ou « Au premier contact de la période ». */
    public static function windowLabel(?int $from, ?int $to): string
    {
        if ($from === null || $to === null) {
            return 'Au premier contact de la période';
        }

        return "{$from} à {$to} SA + 6 j";
    }
}
