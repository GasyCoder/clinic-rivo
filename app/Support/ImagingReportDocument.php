<?php

namespace App\Support;

use App\Enums\ImagingModality;
use App\Models\ImagingRequestItem;
use App\Models\User;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Carbon\CarbonInterface;

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
        if ($item->resulted_at === null) {
            return null;
        }

        return self::compose(
            $item,
            $item->result_value,
            $item->result_notes,
            $item->resulted_at,
            $item->loadMissing(self::RELATIONS)->resultedBy?->name,
            $richText,
            $item->report_sheet_title,
        );
    }

    /**
     * Le compte rendu tel qu'il s'imprimerait **avant** d'être enregistré.
     *
     * Même composition que le document définitif — le médecin relit ce que la
     * famille emportera, pas une approximation —, avec la date du moment et le
     * médecin connecté comme signataire. Rien n'est écrit : ni compte rendu, ni
     * audit, ni état de l'examen.
     *
     * @return array<string, mixed>|null
     */
    public static function preview(
        ImagingRequestItem $item,
        string $value,
        ?string $notes,
        User $actor,
        ClinicalRichTextSanitizer $richText,
        ?string $sheetTitle = null,
    ): ?array {
        return self::compose($item, $value, $notes, now(), $actor->name, $richText, $sheetTitle);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function compose(
        ImagingRequestItem $item,
        ?string $value,
        ?string $notes,
        CarbonInterface $resultedAt,
        ?string $resultedBy,
        ClinicalRichTextSanitizer $richText,
        ?string $sheetTitle = null,
    ): ?array {
        $item->loadMissing(self::RELATIONS);

        $request = $item->imagingRequest;
        $episode = $request?->episode;
        $patient = $episode?->patient;

        if ($patient === null) {
            return null;
        }

        $html = $richText->toSafeHtml($value);

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
            // L'intitulé de la feuille choisie, tel que le papier l'écrit ; sans
            // feuille, le bandeau garde le nom de l'examen.
            'sheet_title' => $sheetTitle,
            'code' => $item->catalog_item_code_snapshot,
            'value' => $html,
            // La feuille papier est en deux colonnes suivies de cases pleine
            // largeur : le découpage se fait ici, une fois, plutôt que dans
            // chaque écran qui la dessine.
            'regions' => self::regions($html),
            'notes' => $richText->toSafeHtml($notes),
            'resulted_at' => $resultedAt,
            'resulted_by' => $resultedBy,
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

    /**
     * Le compte rendu en régions, séparées par les sauts `<hr>` des feuilles
     * de la clinique (ADR-108) : colonne de gauche, colonne de droite, puis
     * les cases pleine largeur. Un texte sans saut — une saisie libre, ou une
     * feuille dont le médecin a effacé les traits — n'est qu'une région.
     *
     * @return array<int, string>
     */
    public static function regions(string $html): array
    {
        $parts = preg_split('/<hr\s*\/?>/i', $html) ?: [$html];
        $parts = array_map('trim', $parts);

        // Un trait final ne crée pas de région vide ; une colonne vide entre
        // deux traits reste vide : c'est la mise en page.
        while ($parts !== [] && $parts[array_key_last($parts)] === '') {
            array_pop($parts);
        }

        return $parts === [] ? [''] : array_values($parts);
    }
}
