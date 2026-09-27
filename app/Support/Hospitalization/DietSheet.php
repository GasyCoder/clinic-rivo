<?php

namespace App\Support\Hospitalization;

use App\Models\HospitalDietEntry;
use App\Models\HospitalStay;

/**
 * ADR-113 — ce que la fiche de régime imprimée lit du séjour, et rien d'autre.
 *
 * Une seule définition pour la fiche d'un séjour et pour les fiches réunies de
 * plusieurs séjours (ADR-165) : deux projections finiraient par imprimer deux
 * feuilles différentes. Tout vient du dossier : les allergies permanentes, le
 * tabac de la fiche Soins, le motif de la demande. Une absence reste une
 * absence — un tabac non renseigné n'est jamais écrit « Non ».
 */
class DietSheet
{
    /**
     * Ce que la fiche charge, pour un séjour comme pour une sélection.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'episode:id,uuid,episode_number,patient_id',
            'episode.patient:id,uuid,patient_number,first_name,last_name,sex,birth_date,declared_age',
            'episode.patient.allergies' => fn ($query) => $query->orderBy('substance'),
            'episode.careRecord:id,episode_id,smoker',
            'hospitalizationRequest:id,reason',
            'dietEntries' => fn ($query) => $query->orderBy('served_on')->orderBy('served_time')->orderBy('id'),
        ];
    }

    /** @return array<string, mixed> */
    public function present(HospitalStay $stay): array
    {
        $stay->loadMissing(self::relations());

        $patient = $stay->episode->patient;
        $smoker = $stay->episode->careRecord?->smoker;

        return [
            'uuid' => $stay->uuid,
            'episode' => ['episode_number' => $stay->episode->episode_number],
            'patient' => [
                'patient_number' => $patient?->patient_number,
                'name' => trim(($patient?->last_name ?? '').' '.($patient?->first_name ?? '')),
            ],
            'allergies' => $patient?->allergies
                ->map(fn ($allergy) => $allergy->substance)
                ->filter()
                ->values()
                ->all() ?? [],
            'smoker' => $smoker === null ? null : (bool) $smoker,
            'request' => ['reason' => $stay->hospitalizationRequest?->reason],
            'diet_entries' => $stay->dietEntries
                ->map(fn (HospitalDietEntry $entry): array => [
                    'uuid' => $entry->uuid,
                    'served_on' => $entry->served_on?->toDateString(),
                    'served_time' => $entry->served_time,
                    'tea_bread' => $entry->tea_bread,
                    'sosoa_brochette' => $entry->sosoa_brochette,
                    'yogurt' => $entry->yogurt,
                    'puree' => $entry->puree,
                    'observation' => $entry->observation,
                ])
                ->values()
                ->all(),
        ];
    }
}
