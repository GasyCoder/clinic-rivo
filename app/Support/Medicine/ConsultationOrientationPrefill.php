<?php

namespace App\Support\Medicine;

use App\Enums\ClinicalSystemStatus;
use App\Enums\PrescriptionStatus;
use App\Models\Consultation;
use App\Models\ImagingRequest;
use App\Models\LabRequest;

/**
 * Ce qu'une demande de conduite à tenir emporte du dossier, composé une seule
 * fois (ADR-084, §17) : motif, résumé clinique, diagnostics, résultats
 * paracliniques et traitements. L'écran le relit, et la clôture le transmet
 * tel quel quand le médecin conclut sans rien retoucher (ADR-177, amendement
 * du 2026-09-27) — jamais une seconde composition qui finirait par diverger.
 *
 * Une valeur absente reste absente (`null`), jamais inventée.
 */
final class ConsultationOrientationPrefill
{
    /** @return array{reason: ?string, clinical_summary: ?string, diagnosis: ?string, paraclinical: ?string, treatments: ?string} */
    public static function compose(Consultation $consultation): array
    {
        $examination = $consultation->clinicalExamination;
        $findings = $examination
            ? $examination->systems()
                ->filter(fn (array $entry): bool => $entry['status'] === ClinicalSystemStatus::Abnormal)
                ->map(fn (array $entry): string => $entry['system']->label().' : '.trim((string) $entry['findings']))
                ->values()
                ->all()
            : [];

        $clinicalSummary = collect([
            $examination?->general_condition?->label() ? 'État général : '.$examination->general_condition->label() : null,
            $examination?->consciousness_status?->label() ? 'Conscience : '.$examination->consciousness_status->label() : null,
            ...$findings,
            self::toPlainText($consultation->clinical_exam),
        ])->filter()->implode("\n");

        $paraclinical = $consultation->labRequests()
            ->whereNull('cancelled_at')
            ->with('items')
            ->get()
            ->flatMap(fn (LabRequest $request) => $request->items->map(
                fn ($item): string => self::paraclinicalLine($item->catalog_item_name_snapshot, $item->result_value),
            ))
            ->merge($consultation->imagingRequests()
                ->whereNull('cancelled_at')
                ->with('items')
                ->get()
                ->flatMap(fn (ImagingRequest $request) => $request->items->map(
                    fn ($item): string => self::paraclinicalLine($item->catalog_item_name_snapshot, $item->result_value),
                )))
            ->implode("\n");

        $treatments = $consultation->prescriptions()
            ->where('status', PrescriptionStatus::Active->value)
            ->with('lines')
            ->get()
            ->flatMap(fn ($prescription) => $prescription->lines->map(fn ($line): string => trim(collect([
                $line->medication_name,
                $line->dosage,
                $line->route?->shortLabel(),
                $line->frequency,
                $line->duration,
            ])->filter()->implode(' · '))))
            ->implode("\n");

        return [
            'reason' => $consultation->chief_complaint ?: self::toPlainText($consultation->reason),
            'clinical_summary' => $clinicalSummary !== '' ? $clinicalSummary : null,
            'diagnosis' => $consultation->diagnoses()
                ->whereDoesntHave('cancellation')
                ->pluck('description')
                ->implode("\n") ?: null,
            'paraclinical' => $paraclinical !== '' ? $paraclinical : null,
            'treatments' => $treatments !== '' ? $treatments : null,
        ];
    }

    /**
     * Une ligne de résultat paraclinique, en texte.
     *
     * Un compte rendu d'imagerie est saisi en éditeur riche et stocké en HTML
     * (ADR-070) ; cette ligne rejoint un `<textarea>`, où le balisage
     * s'affiche tel quel. Le préremplissage montrait donc au médecin
     * « UTERUS<p>• Orientation… </p><p>• Volume… » — et c'est ce texte-là
     * qui serait parti au service d'accueil.
     */
    private static function paraclinicalLine(string $name, ?string $result): string
    {
        $text = self::toPlainText($result);

        if ($text === null) {
            return trim($name).' — en attente';
        }

        // Un compte rendu tient sur plusieurs lignes : il est présenté sous
        // son examen plutôt que collé derrière, sinon la première ligne
        // absorbe le nom et les suivantes flottent sans rattachement.
        return str_contains($text, "\n")
            ? trim($name)." :\n".$text
            : trim($name).' : '.$text;
    }

    /**
     * Du HTML de l'éditeur riche vers du texte lisible.
     *
     * Seuls `</p>` et `<br>` produisaient un retour à la ligne : une liste à
     * puces ou des titres se retrouvaient collés en une seule phrase. Chaque
     * fin de bloc en produit un désormais, et les lignes vides consécutives
     * sont réduites — un compte rendu doit rester relisible, pas fidèle à
     * une mise en page qu'un champ de texte ne rend pas.
     */
    public static function toPlainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $withBreaks = preg_replace(
            [
                '/<br\s*\/?>/i',
                // L'ouverture compte autant que la fermeture : un compte rendu
                // écrit « UTERUS<p>• Orientation… » sans fermer avant, et seule
                // la fermeture cassant la ligne, les deux restaient collés.
                '/<(p|div|li|h[1-6]|tr|blockquote)(\s[^>]*)?>/i',
                '/<\/(p|div|li|h[1-6]|tr|blockquote)\s*>/i',
            ],
            "\n",
            $html,
        ) ?? $html;

        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5);
        // Espaces insécables compris : l'éditeur en produit, et `trim()` seul
        // les laisse en début de ligne.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        // Une ligne vide sur deux : ouverture *et* fermeture d'un même bloc
        // cassent la ligne, et un champ de texte n'a pas d'interlignage à
        // restituer. Le compte rendu se lit d'un bloc, ligne à ligne.
        $text = trim(preg_replace('/\n{2,}/', "\n", $text) ?? $text);

        return $text !== '' ? $text : null;
    }
}
