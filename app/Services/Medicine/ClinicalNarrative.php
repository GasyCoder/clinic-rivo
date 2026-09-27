<?php

namespace App\Services\Medicine;

use App\Enums\ClinicalSystemStatus;
use App\Models\Consultation;

/**
 * Ce que le médecin a écrit d'un patient — et seulement cela (ADR-111).
 *
 * Les deux moteurs de proposition lisent ce texte : les protocoles y
 * cherchent leurs signes, la pratique de la clinique en apprend son
 * vocabulaire. Il est donc écrit une seule fois ; deux lectures divergeraient.
 *
 * Le motif est **prérempli** avec le nom de la prestation demandée
 * (« Consultation de médecine générale », « Échographie obstétricale »),
 * pour que le médecin ne parte pas d'une page blanche. Ce n'est pas un
 * symptôme : c'est l'orientation du passage. Le laisser dans le texte ferait
 * apprendre que « générale » évoque un diagnostic — il est donc retiré, même
 * quand le médecin a complété le motif à sa suite.
 */
final class ClinicalNarrative
{
    public static function of(Consultation $consultation): string
    {
        $consultation->loadMissing(['episode.serviceRequests', 'clinicalExamination.findings']);

        $exam = $consultation->clinicalExamination;
        $texts = [
            $consultation->chief_complaint,
            self::withoutPrefill($consultation),
            $consultation->symptom_onset,
            $consultation->additional_notes,
            $consultation->clinical_exam,
            $exam?->general_observation,
            $exam?->consciousness_details,
        ];

        // Seul un appareil **anormal** porte des constatations : un appareil
        // normal n'en conserve aucune (ADR-077), un appareil non examiné n'a
        // pas de ligne.
        foreach ($exam?->findings ?? [] as $finding) {
            if ($finding->status === ClinicalSystemStatus::Abnormal) {
                $texts[] = $finding->findings;
            }
        }

        return implode(' ', array_map(fn ($text) => strip_tags((string) $text), $texts));
    }

    private static function withoutPrefill(Consultation $consultation): string
    {
        $reason = strip_tags((string) $consultation->reason);
        $prefills = collect($consultation->episode?->serviceRequests ?? [])
            ->pluck('designation')
            ->filter()
            ->push('Motif à préciser')
            // Le plus long d'abord : « Consultation de médecine générale »
            // avant un éventuel « Consultation ».
            ->sortByDesc(fn ($text) => mb_strlen((string) $text))
            ->all();

        foreach ($prefills as $prefill) {
            $reason = str_ireplace((string) $prefill, ' ', $reason);
        }

        return $reason;
    }
}
