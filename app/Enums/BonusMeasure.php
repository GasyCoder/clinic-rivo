<?php

namespace App\Enums;

/**
 * ADR-212 — ce qu'une catégorie de bonus compte, chaque mois, pour chaque
 * membre du personnel concerné : des **patients distincts**, jamais des actes.
 *
 * Chaque mesure lit un fait déjà enregistré et attribué à une personne ; rien
 * n'est déduit d'un libellé. Les patients recommandés se lisent sur la fiche
 * de l'employé ; les patients soignés sur son compte de connexion (le lien
 * compte ↔ fiche de l'ADR-188) — sans compte relié, il n'en a aucun.
 */
enum BonusMeasure: string
{
    case ReferredPatients = 'REFERRED_PATIENTS';
    case Consultations = 'CONSULTATIONS';
    case Surgeries = 'SURGERIES';
    case LabResults = 'LAB_RESULTS';
    case ImagingResults = 'IMAGING_RESULTS';
    case CareActs = 'CARE_ACTS';
    case MaternityActs = 'MATERNITY_ACTS';

    public function label(): string
    {
        return match ($this) {
            self::ReferredPatients => 'Patients recommandés',
            self::Consultations => 'Patients consultés',
            self::Surgeries => 'Patients opérés',
            self::LabResults => 'Patients — analyses rendues',
            self::ImagingResults => 'Patients — imagerie rendue',
            self::CareActs => 'Patients — actes de soins',
            self::MaternityActs => 'Patients — actes de maternité',
        };
    }

    /** Le fait compté, dit en une phrase. */
    public function description(): string
    {
        return match ($this) {
            self::ReferredPatients => 'Nouveaux patients dont il est la personne qui a recommandé la clinique, à leur arrivée.',
            self::Consultations => 'Consultations clôturées dont il est le médecin.',
            self::Surgeries => 'Interventions terminées dont il est l’opérateur.',
            self::LabResults => 'Résultats d’analyses qu’il a rendus.',
            self::ImagingResults => 'Comptes rendus d’imagerie qu’il a rendus.',
            self::CareActs => 'Actes de soins qu’il a réalisés.',
            self::MaternityActs => 'Actes de maternité qu’il a réalisés.',
        };
    }

    /** Les patients soignés passent par le compte de connexion ; les patients recommandés, non. */
    public function needsAccount(): bool
    {
        return $this !== self::ReferredPatients;
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $measure) => ['value' => $measure->value, 'label' => $measure->label(), 'description' => $measure->description()],
            self::cases(),
        );
    }
}
