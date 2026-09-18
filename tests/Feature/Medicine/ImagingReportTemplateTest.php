<?php

namespace Tests\Feature\Medicine;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Support\ImagingReportTemplates;
use Tests\TestCase;

/**
 * ADR-108 — les feuilles de compte rendu d'échographie de la clinique.
 *
 * Ce que ces tests protègent n'est pas la mise en page : c'est que la feuille
 * arrive intacte au médecin, et qu'elle ne redemande rien de ce que le
 * dossier porte déjà.
 */
class ImagingReportTemplateTest extends TestCase
{
    /** Le canevas passe par le même filtre que ce que le médecin tape. */
    public function test_every_template_survives_the_clinical_sanitizer(): void
    {
        $sanitizer = $this->app->make(ClinicalRichTextSanitizer::class);

        foreach (ImagingReportTemplates::all() as $template) {
            $once = $sanitizer->sanitize($template['body_html']);

            // Stable : nettoyer deux fois donne le même texte, donc aucune
            // section ne disparaît au deuxième enregistrement.
            $this->assertSame($once, $sanitizer->sanitize($once), $template['key']);

            // Et rien n'est vidé : une feuille filtrée à blanc serait pire
            // qu'une absence de feuille.
            $this->assertNotSame('', trim(strip_tags($once)), $template['key']);
        }
    }

    /** Les rubriques du formulaire papier, dans l'ordre où il les pose. */
    public function test_the_abdominal_sheet_carries_every_section_of_the_paper_form(): void
    {
        $body = $this->bodyOf('ECHO_ABDOMINO_PELVIENNE');

        foreach ([
            'FOIE', 'REIN DROIT', 'REIN GAUCHE', 'PANCRÉAS', 'RATE', 'VESSIE',
            'UTERUS', 'OVAIRE DROIT', 'OVAIRE GAUCHE', 'CUL DE SAC DE DOUGLAS',
            'PROSTATE', 'CONCLUSION',
        ] as $section) {
            $this->assertStringContainsString($section, $body);
        }
    }

    public function test_the_obstetric_sheet_carries_every_section_of_the_paper_form(): void
    {
        $body = $this->bodyOf('ECHO_OBSTETRICALE_T1');

        foreach ([
            'UTERUS', 'OVAIRES', 'SAC OVULAIRE', 'EMBRYON', 'CONCLUSION',
            'OBSERVATION', 'N.B.',
        ] as $section) {
            $this->assertStringContainsString($section, $body);
        }

        // Les mesures fœtales, qui sont tout l'intérêt de cette feuille.
        foreach ([
            'Longueur cranio-caudale', 'Diamètre bipariétal',
            'Circonférence abdominale', 'Longueur du fémur',
            'Activité cardiaque', 'Clarté nucale',
        ] as $measure) {
            $this->assertStringContainsString($measure, $body);
        }
    }

    /**
     * L'en-tête, le numéro de dossier, l'identité du patient, « Fait le » et
     * « Le médecin responsable » sont déjà produits à l'impression
     * (`ClinicalDocumentPrint`). Les remettre dans le canevas les ferait
     * diverger du dossier, et l'ADR-084 pose qu'aucune donnée déjà consignée
     * n'est redemandée.
     */
    public function test_no_template_asks_again_for_what_the_dossier_already_holds(): void
    {
        foreach (ImagingReportTemplates::all() as $template) {
            $body = strip_tags($template['body_html']);

            foreach ([
                'N° DE DOSSIER', 'Nom et Prénom', 'Date de Naissance', 'Adresse',
                'Fait le', 'médecin responsable',
            ] as $alreadyPrinted) {
                $this->assertStringNotContainsString($alreadyPrinted, $body, $template['key']);
            }
        }
    }

    /**
     * Rien n'est déduit du nom ni du code d'un examen (ADR-052) : la feuille
     * est un choix du médecin, pas une correspondance automatique.
     */
    public function test_a_template_names_no_catalog_item(): void
    {
        foreach (ImagingReportTemplates::all() as $template) {
            $this->assertArrayNotHasKey('catalog_item_uuid', $template);
            $this->assertArrayNotHasKey('matches', $template);
            $this->assertSame(
                ['key', 'label', 'description', 'body_html'],
                array_keys($template),
            );
        }
    }

    private function bodyOf(string $key): string
    {
        $template = collect(ImagingReportTemplates::all())->firstWhere('key', $key);

        $this->assertNotNull($template, "La feuille {$key} a disparu.");

        return strip_tags($template['body_html']);
    }
}
