<?php

namespace App\Support\Medicine;

use App\Enums\EpisodeMedicalStatus;
use App\Enums\MedicalDischargeType;

/**
 * Ce qu'une sortie médicale écrit, quel que soit l'écran qui la prononce.
 *
 * La consultation et le séjour (ADR-162) prononcent la même sortie : les
 * règles de l'ADR-107 (un décès n'a ni état à choisir, ni traitement de
 * sortie, ni conseils, ni rendez-vous) sont écrites ici une fois, pour que
 * les deux chemins ne puissent pas diverger.
 */
final class MedicalDischargeAttributes
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function from(array $data, MedicalDischargeType $type): array
    {
        $deceased = $type === MedicalDischargeType::Deceased;
        $finalDiagnosis = trim((string) ($data['final_diagnosis'] ?? ''));

        return [
            'type' => $type,
            // ADR-094 — une absence reste une absence, jamais une chaîne vide.
            'final_diagnosis' => $finalDiagnosis !== '' ? $finalDiagnosis : null,
            // ADR-107 — pour un décès, le type de sortie *est* l'état du patient.
            'patient_condition' => $deceased
                ? EpisodeMedicalStatus::Deceased->label()
                : trim((string) ($data['patient_condition'] ?? '')),
            'discharge_prescription' => $deceased ? null : ($data['discharge_prescription'] ?? null),
            'recommendations' => $deceased ? null : ($data['recommendations'] ?? null),
            'follow_up_at' => $deceased ? null : ($data['follow_up_at'] ?? null),
            'observations' => $data['observations'] ?? null,
            'transfer_destination' => $data['transfer_destination'] ?? null,
            'death_occurred_at' => $data['death_occurred_at'] ?? null,
            'death_place' => $data['death_place'] ?? null,
            'death_causes' => $data['death_causes'] ?? null,
            'discharged_at' => $data['discharged_at'] ?? now(),
        ];
    }
}
