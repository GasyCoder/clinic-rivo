<?php

namespace Tests\Feature\Medicine;

use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Support\ImagingReportDocument;
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

        $this->assertInOrder($body, [
            'FOIE', 'REIN DROIT', 'REIN GAUCHE', 'PANCRÉAS', 'RATE', 'VESSIE',
            'UTERUS', 'OVAIRE DROIT', 'OVAIRE GAUCHE', 'CUL DE SAC DE DOUGLAS',
            'N.B.', 'CONCLUSION',
        ]);

        // La version avec N.B. n'a pas de rubrique prostate : c'est l'autre
        // version du papier, reprise à part.
        $this->assertStringNotContainsString('PROSTATE', $body);
        // Et le diamètre des reins y est écrit tel quel.
        $this->assertStringContainsString('Diamètre bipariétal :', $body);
    }

    /** La seconde version du papier : Prostate à la place du N.B., « Diamètre: » sur les reins. */
    public function test_the_prostate_variant_of_the_abdominal_sheet_matches_its_paper_form(): void
    {
        $body = $this->bodyOf('ECHO_ABDOMINO_PELVIENNE_PROSTATE');

        $this->assertInOrder($body, [
            'FOIE', 'REIN DROIT', 'Diamètre:', 'REIN GAUCHE', 'Diamètre:', 'PANCRÉAS', 'RATE',
            'VESSIE', 'UTERUS', 'OVAIRE DROIT', 'OVAIRE GAUCHE', 'CUL DE SAC DE DOUGLAS',
            'PROSTATE :', 'Mesure:', 'Volume:', 'CONCLUSION',
        ]);
        $this->assertStringNotContainsString('N.B.', $body);
        $this->assertStringNotContainsString('bipariétal', $body);

        $regions = $this->regionsOf('ECHO_ABDOMINO_PELVIENNE_PROSTATE');
        $this->assertCount(3, $regions);
        $this->assertStringContainsString('PROSTATE', $regions[1]);
        $this->assertStringContainsString('CONCLUSION', $regions[2]);
    }

    public function test_the_pelvic_sheet_carries_every_section_of_the_paper_form(): void
    {
        $body = $this->bodyOf('ECHO_PELVIENNE');

        $this->assertInOrder($body, [
            'UTERUS', 'Orientation:', 'Volume', 'Longueur:', 'Epaisseur:', 'Largeur:',
            'Contour:', 'Myomètre:', 'Endomètre:', 'Echocavitaire:',
            'OVAIRES:', 'Droite:', 'Gauche:', 'Autres organes pelviens:',
            'Masse pelvienne :', 'CONCLUSION :', 'N.B. :',
        ]);
    }

    public function test_the_obstetric_first_trimester_sheet_carries_every_section_of_the_paper_form(): void
    {
        $body = $this->bodyOf('ECHO_OBSTETRICALE_T1');

        $this->assertInOrder($body, [
            'UTERUS', 'OVAIRES', 'SAC OVULAIRE', 'EMBRYON',
            'Longueur Cranio-caudale', 'Diamètre bipariétal', 'Circonférence abdominale',
            'Longueur du fémur', 'Activité cardiaque', 'Mouvement embryo-foetaux',
            'Morphologie', 'Clarté nucale',
            'CONCLUSION', 'Date de fécondation probable', 'DAP Echographique', 'Terme le',
            'Contrôle souhaitable', 'OBSERVATION', 'N.B.',
        ]);
    }

    public function test_the_obstetric_second_third_trimester_sheet_carries_every_section_of_the_paper_form(): void
    {
        $body = $this->bodyOf('ECHO_OBSTETRICALE_T2_T3');

        $this->assertInOrder($body, [
            'FOETUS:', 'Grossesse:', 'Présentation:', 'Céphalique', 'Siège', 'Transversale', 'Oblique',
            'Biométrie', 'Diamètre bi-pariétale', 'Percentile de', 'Circonférence de la tête', 'FL', 'CA',
            'POIDS FOETAL ESTIME', 'MORPHOLOGIE', 'Pôle céphalique', 'Thorax', 'Abdomen', 'Membre',
            'PHYSIOLOGIE:', 'AC', 'MAF', 'Movement respiratoire:',
            'VOLUME DE LIQUIDE AMNIOTIQUE:', 'Normale', 'Hydramnios', 'Oligoamnios',
            'CORDON OMBILICAL', 'Circulaire', 'Vaisseaux', 'Artère', 'Veine',
            'INSERTION PLACENTAIRE:', 'Localisation', 'Antérieur', 'Postérieur', 'Fundique',
            'Echo structure:', 'Grade:', 'CONCLUSION :', 'VITALITE FOETAL :', 'N.B :',
        ]);
    }

    /**
     * La mise en page du papier : deux colonnes, puis des cases pleine
     * largeur. Ce qui compte, c'est **dans quelle case** tombe chaque rubrique.
     */
    public function test_each_sheet_puts_its_sections_in_the_column_of_the_paper_form(): void
    {
        $abdomen = $this->regionsOf('ECHO_ABDOMINO_PELVIENNE');
        $this->assertCount(3, $abdomen);
        $this->assertStringContainsString('FOIE', $abdomen[0]);
        $this->assertStringContainsString('RATE', $abdomen[0]);
        $this->assertStringContainsString('VESSIE', $abdomen[1]);
        $this->assertStringContainsString('N.B.', $abdomen[1]);
        $this->assertStringContainsString('CONCLUSION', $abdomen[2]);

        $pelvic = $this->regionsOf('ECHO_PELVIENNE');
        $this->assertCount(3, $pelvic);
        $this->assertStringContainsString('UTERUS', $pelvic[0]);
        $this->assertStringContainsString('OVAIRES', $pelvic[0]);
        $this->assertStringContainsString('CONCLUSION', $pelvic[1]);
        $this->assertStringContainsString('N.B.', $pelvic[2]);

        // La rubrique EMBRYON est coupée entre les deux colonnes.
        $first = $this->regionsOf('ECHO_OBSTETRICALE_T1');
        $this->assertCount(3, $first);
        $this->assertStringContainsString('EMBRYON', $first[0]);
        $this->assertStringContainsString('Nombre: Unique / Multiple', $first[0]);
        $this->assertStringContainsString('Longueur Cranio-caudale', $first[1]);
        $this->assertStringContainsString('CONCLUSION', $first[1]);
        $this->assertStringContainsString('OBSERVATION', $first[1]);
        $this->assertStringContainsString('N.B.', $first[2]);

        $late = $this->regionsOf('ECHO_OBSTETRICALE_T2_T3');
        $this->assertCount(3, $late);
        $this->assertStringContainsString('FOETUS', $late[0]);
        $this->assertStringContainsString('VOLUME DE LIQUIDE AMNIOTIQUE', $late[0]);
        $this->assertStringContainsString('CORDON OMBILICAL', $late[1]);
        $this->assertStringContainsString('VITALITE FOETAL', $late[1]);
        $this->assertStringContainsString('N.B', $late[2]);
    }

    /**
     * Le compte rendu est plafonné à 10 000 caractères de HTML : une feuille
     * qui en consommerait la moitié à vide ne laisserait plus de place pour
     * écrire.
     */
    public function test_a_blank_sheet_leaves_room_to_write(): void
    {
        $sanitizer = $this->app->make(ClinicalRichTextSanitizer::class);

        foreach (ImagingReportTemplates::all() as $template) {
            $this->assertLessThan(
                3500,
                mb_strlen($sanitizer->sanitize($template['body_html'])),
                $template['key'].' consomme trop du plafond de 10 000 caractères.',
            );
        }
    }

    public function test_the_column_break_survives_the_sanitizer(): void
    {
        $sanitizer = $this->app->make(ClinicalRichTextSanitizer::class);

        $clean = $sanitizer->sanitize('<p>Gauche</p><hr class="x" onclick="alert(1)"><p>Droite</p>');

        $this->assertSame('<p>Gauche</p><hr><p>Droite</p>', $clean);
    }

    public function test_the_document_splits_a_report_into_regions(): void
    {
        $this->assertSame(['<p>a</p>', '<p>b</p>', '<p>c</p>'], ImagingReportDocument::regions('<p>a</p><hr><p>b</p><hr><p>c</p>'));
        // Un texte libre n'a qu'une région : l'ancien rendu est conservé.
        $this->assertSame(['<p>a</p>'], ImagingReportDocument::regions('<p>a</p>'));
        // Un trait final ne fabrique pas de case vide.
        $this->assertSame(['<p>a</p>', '<p>b</p>'], ImagingReportDocument::regions('<p>a</p><hr><p>b</p><hr>'));
        // Une colonne vide entre deux traits reste vide : c'est la mise en page.
        $this->assertSame(['<p>a</p>', '', '<p>c</p>'], ImagingReportDocument::regions('<p>a</p><hr><hr><p>c</p>'));
        $this->assertSame([''], ImagingReportDocument::regions(''));
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
                ['key', 'label', 'title', 'description', 'body_html', 'validated'],
                array_keys($template),
            );
        }
    }

    /**
     * Les propositions du système ne prétendent pas être un papier de la
     * clinique : elles le disent (`validated`), et ne portent que des rubriques
     * à compléter — jamais une valeur, une norme ni un seuil.
     */
    public function test_proposals_are_flagged_and_carry_no_value(): void
    {
        $paper = ['ECHO_ABDOMINO_PELVIENNE', 'ECHO_ABDOMINO_PELVIENNE_PROSTATE', 'ECHO_PELVIENNE', 'ECHO_OBSTETRICALE_T1', 'ECHO_OBSTETRICALE_T2_T3'];

        foreach (ImagingReportTemplates::all() as $template) {
            $isPaper = in_array($template['key'], $paper, true);
            $this->assertSame($isPaper, $template['validated'], $template['key']);

            if (! $isPaper) {
                $this->assertStringEndsWith('(proposition)', $template['label'], $template['key']);
                $this->assertDoesNotMatchRegularExpression('/\d/', strip_tags($template['body_html']), $template['key'].' contient un chiffre : une proposition ne fixe aucune valeur.');
                $this->assertGreaterThanOrEqual(3, count(ImagingReportDocument::regions($template['body_html'])), $template['key']);
                $this->assertStringContainsString('CONCLUSION', strip_tags($template['body_html']), $template['key']);
            }
        }
    }

    /** Chaque examen réglé d'office doit désigner une feuille qui existe. */
    public function test_every_default_points_at_an_existing_sheet(): void
    {
        $keys = array_column(ImagingReportTemplates::all(), 'key');

        foreach (ImagingReportTemplates::DEFAULT_BY_EXAM_CODE as $code => $key) {
            $this->assertContains($key, $keys, "{$code} désigne une feuille inexistante.");
        }
    }

    /** Le bandeau reprend le titre du papier, casse comprise. */
    public function test_paper_titles_are_those_of_the_forms(): void
    {
        $titles = array_column(ImagingReportTemplates::all(), 'title', 'key');

        $this->assertSame('ÉCHOGRAPHIE ABDOMINO-PELVIENNE', $titles['ECHO_ABDOMINO_PELVIENNE']);
        $this->assertSame('ÉCHOGRAPHIE PELVIENNE', $titles['ECHO_PELVIENNE']);
        $this->assertSame('ÉCHOGRAPHIE OBSTETRICALE (1er TRIMESTRE)', $titles['ECHO_OBSTETRICALE_T1']);
        $this->assertSame('ÉCHOGRAPHIE OBSTÉTRICALE (2ème – 3ème TRIMESTRE)', $titles['ECHO_OBSTETRICALE_T2_T3']);
    }

    /** @param  array<int, string>  $expected */
    private function assertInOrder(string $body, array $expected): void
    {
        $offset = 0;

        foreach ($expected as $label) {
            $position = strpos($body, $label, $offset);

            $this->assertNotFalse($position, "« {$label} » manque, ou n'est pas à sa place dans la feuille.");
            $offset = $position + strlen($label);
        }
    }

    /** @return array<int, string> */
    private function regionsOf(string $key): array
    {
        $template = collect(ImagingReportTemplates::all())->firstWhere('key', $key);

        return array_map(
            static fn (string $region): string => strip_tags($region),
            ImagingReportDocument::regions($template['body_html']),
        );
    }

    private function bodyOf(string $key): string
    {
        $template = collect(ImagingReportTemplates::all())->firstWhere('key', $key);

        $this->assertNotNull($template, "La feuille {$key} a disparu.");

        return strip_tags($template['body_html']);
    }
}
