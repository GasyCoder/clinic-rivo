<?php

namespace App\Support\Maternity;

/**
 * ADR-204 — le vocabulaire des champs structurés d'une prise en charge
 * Maternité, écrit une fois.
 *
 * La validation (`UpdateMaternityRecordRequest`) et l'écran lisent les mêmes
 * listes : Vue ne recopie aucun libellé. Ces choix **structurent** la saisie ;
 * aucun n'est un diagnostic, et aucun n'est obligatoire — « non évalué » est
 * une réponse, une case vide aussi.
 *
 * Tout vit dans `prenatal_data` (et `labor_data` pour l'admission) : ce sont
 * des données du passage, jamais de la grossesse longitudinale (ADR-201).
 */
final class MaternityEncounterFields
{
    /** Pourquoi la patiente vient aujourd'hui. */
    public const VISIT_REASONS = [
        'SCHEDULED_FOLLOW_UP' => 'Suivi prénatal programmé',
        'COMPLAINT' => 'Plainte / symptôme',
        'RESULT_REVIEW' => 'Contrôle de résultat',
        'OTHER' => 'Autre',
    ];

    /** Ce que la patiente rapporte depuis la dernière consultation — des cases, jamais une conclusion. */
    public const REPORTED_SINCE_LAST = [
        'EVENT' => 'Événement particulier',
        'HOSPITALIZATION' => 'Hospitalisation',
        'TREATMENT_CHANGE' => 'Traitement commencé ou modifié',
        'SYMPTOMS' => 'Symptômes',
        'FETAL_MOVEMENTS' => 'Mouvements fœtaux',
        'CONTRACTIONS' => 'Contractions',
        'URINARY_SYMPTOMS' => 'Symptômes urinaires',
        'OTHER' => 'Autre',
    ];

    public const FETAL_MOVEMENTS = [
        'PRESENT' => 'Présents',
        'DECREASED' => 'Diminués',
        'NOT_ASSESSED' => 'Non évalués',
    ];

    public const CONTRACTIONS = [
        'NO' => 'Non',
        'YES' => 'Oui',
        'NOT_ASSESSED' => 'Non évaluées',
    ];

    public const PRESENTATIONS = [
        'CEPHALIC' => 'Céphalique',
        'BREECH' => 'Siège',
        'TRANSVERSE' => 'Transverse',
        'OTHER' => 'Autre',
        'NOT_ASSESSED' => 'Non évaluée',
    ];

    /** Le motif proposé pour le prochain rendez-vous ; il reste modifiable. */
    public const DEFAULT_APPOINTMENT_REASON = 'Suivi prénatal';

    /** @return list<string> */
    public static function keys(array $options): array
    {
        return array_keys($options);
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function options(): array
    {
        $list = fn (array $options): array => array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys($options),
            array_values($options),
        );

        return [
            'visit_reasons' => $list(self::VISIT_REASONS),
            'reported_since_last' => $list(self::REPORTED_SINCE_LAST),
            'fetal_movements' => $list(self::FETAL_MOVEMENTS),
            'contractions' => $list(self::CONTRACTIONS),
            'presentations' => $list(self::PRESENTATIONS),
            'default_appointment_reason' => [['value' => self::DEFAULT_APPOINTMENT_REASON, 'label' => self::DEFAULT_APPOINTMENT_REASON]],
        ];
    }
}
