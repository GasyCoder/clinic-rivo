<?php

namespace App\Support;

use App\Enums\ImagingModality;
use App\Models\ImagingRequestItem;
use App\Services\Medicine\ClinicalRichTextSanitizer;

/**
 * Le compte rendu d'imagerie tel que la clinique le remet (ADR-108).
 *
 * Il reprend la feuille papier « RÉSULTATS D'ÉCHOGRAPHIE » : N° de dossier,
 * identité, titre de l'examen, compte rendu, N.B., date et médecin. Une seule
 * source pour l'impression et pour l'affichage à l'écran — deux mises en page
 * du même document finiraient par ne plus dire la même chose.
 *
 * L'identité vient du dossier, jamais du canevas : c'est pourquoi les feuilles
 * de l'ADR-108 ne portent ni nom, ni date de naissance, ni signature.
 */
final class ImagingReportDocument
{
    /** Ce que le document doit charger pour être composé. */
    public const RELATIONS = [
        'catalogItem:id,imaging_modality',
        'resultedBy:id,name',
        'imagingRequest.requestedBy:id,name',
        'imagingRequest.episode.patient.addressEntry:id,label',
    ];

    /** @return array<string, mixed>|null */
    public static function for(ImagingRequestItem $item, ClinicalRichTextSanitizer $richText): ?array
    {
        $item->loadMissing(self::RELATIONS);

        $request = $item->imagingRequest;
        $episode = $request?->episode;
        $patient = $episode?->patient;

        if ($item->resulted_at === null || $patient === null) {
            return null;
        }

        return [
            'uuid' => $item->uuid,
            // Le titre suit la famille réglée au catalogue (ADR-106), jamais
            // le libellé : un examen non classé garde un titre générique.
            'title' => match ($item->catalogItem?->imaging_modality) {
                ImagingModality::Ultrasound => 'Résultats d’échographie',
                ImagingModality::Cardiology => 'Résultats d’électrocardiogramme',
                default => 'Résultats d’imagerie',
            },
            'exam' => $item->catalog_item_name_snapshot,
            'code' => $item->catalog_item_code_snapshot,
            'value' => $richText->toSafeHtml($item->result_value),
            'notes' => $richText->toSafeHtml($item->result_notes),
            'resulted_at' => $item->resulted_at,
            'resulted_by' => $item->resultedBy?->name,
            'requested_at' => $request->requested_at,
            'requested_by' => $request->requestedBy?->name,
            'indication' => $request->notes,
            'episode_number' => $episode->episode_number,
            'patient' => [
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'sex' => $patient->sex->value,
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                'age' => $patient->birth_date?->age ?? $patient->declared_age,
                'address' => $patient->addressEntry?->label ?? $patient->address,
            ],
        ];
    }
}
