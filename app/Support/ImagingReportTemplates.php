<?php

namespace App\Support;

/**
 * Les feuilles de compte rendu d'échographie de la clinique (ADR-108).
 *
 * Ce sont les formulaires papier transmis par le propriétaire, transcrits
 * section par section. Le médecin en choisit un explicitement : **rien n'est
 * déduit du nom ni du code de l'examen** (ADR-052). « Échographie pelvienne »
 * et « Échographie abdomino-pelvienne » se ressemblent assez pour qu'une
 * correspondance automatique finisse par insérer la mauvaise feuille, et un
 * compte rendu commencé sur le mauvais canevas se relit mal.
 *
 * Le canevas ne porte que le **corps clinique**. L'en-tête de la clinique, le
 * numéro de dossier, l'identité du patient, « Fait le » et « Le médecin
 * responsable » sont déjà produits par `ClinicalDocumentPrint` à l'impression :
 * les retaper ici les ferait diverger du dossier, et l'ADR-084 pose qu'aucune
 * donnée déjà consignée n'est redemandée.
 *
 * Le rendu est du HTML restreint aux balises que `ClinicalRichTextSanitizer`
 * accepte — le canevas passe par le même filtre que ce que le médecin tape.
 */
final class ImagingReportTemplates
{
    /**
     * @return array<int, array{key: string, label: string, description: string, body_html: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'ECHO_ABDOMINO_PELVIENNE',
                'label' => 'Échographie abdomino-pelvienne',
                'description' => 'Foie, reins, pancréas, rate, vessie, utérus, ovaires, prostate',
                'body_html' => self::abdominoPelvienne(),
            ],
            [
                'key' => 'ECHO_OBSTETRICALE_T1',
                'label' => 'Échographie obstétricale (1er trimestre)',
                'description' => 'Utérus, ovaires, sac ovulaire, embryon, conclusion',
                'body_html' => self::obstetricaleT1(),
            ],
        ];
    }

    /** @param array<int, string> $lines */
    private static function section(string $title, array $lines): string
    {
        $items = array_map(static fn (string $line): string => '<li>'.$line.'</li>', $lines);

        return '<p><strong>'.$title.'</strong></p><ul>'.implode('', $items).'</ul>';
    }

    /** Une rubrique sans puces : le formulaire y laisse une zone libre. */
    private static function freeSection(string $title): string
    {
        return '<p><strong>'.$title.'</strong></p><p></p>';
    }

    private static function abdominoPelvienne(): string
    {
        return self::section('FOIE', [
            'Échostructure :',
            'Bord : &nbsp; &nbsp; Contours :',
            'Flèche hépatique droite :',
            'Tronc porte :',
            'Canaux biliaires :',
            'Vésicule biliaire :',
            'Espace de Morisson :',
        ])
            .self::section('REIN DROIT', [
                'Diamètre :',
                'Épaisseur de la parenchyme mesure :',
                'Différenciation parenchymo-sinusale :',
                'Lithiase :',
            ])
            .self::section('REIN GAUCHE', [
                'Diamètre :',
                'Parenchyme mesure :',
                'Différenciation parenchymo-sinusale :',
                'Lithiase :',
            ])
            .self::section('PANCRÉAS', [
                'Échostructure :',
                'Mesure : tête - queue - isthme',
            ])
            .self::section('RATE', [
                'Échostructure :',
                'Grand axe mesure :',
                'Diamètre transverse maximum :',
            ])
            .self::section('VESSIE', [
                'Échostructure :',
                'Paroi : &nbsp; &nbsp; Lithiase :',
            ])
            .self::section('UTERUS', [
                'Échostructure :',
                'Position :',
                'Épaisseur de l’endomètre :',
                'Mesure :',
                'Myome :',
            ])
            .self::section('OVAIRE DROIT', [
                'Échostructure :',
                'Mesure : &nbsp; &nbsp; Volume :',
                'Follicules :',
            ])
            .self::section('OVAIRE GAUCHE', [
                'Échostructure :',
                'Mesure : &nbsp; &nbsp; Volume :',
                'Follicules :',
            ])
            .self::freeSection('CUL DE SAC DE DOUGLAS')
            .self::section('PROSTATE', [
                'Mesure : &nbsp; &nbsp; Volume :',
            ])
            .self::freeSection('CONCLUSION');
    }

    private static function obstetricaleT1(): string
    {
        return self::section('UTERUS', [
            'Antéversé / Intermédiaire / Retraversé (fléchi)',
            'Dimension :',
            'Remarque :',
        ])
            // Les sous-points « o » du formulaire : une liste dans la liste,
            // plutôt qu'un caractère « o » en dur qui ne se relit pas.
            .'<p><strong>OVAIRES</strong></p><ul>'
            .'<li>Droite :<ul><li>Taille :</li><li>Échostructure :</li></ul></li>'
            .'<li>Gauche :<ul><li>Taille :</li><li>Échostructure :</li></ul></li>'
            .'</ul>'
            .self::section('SAC OVULAIRE', [
                'Intra utérin / Extra utérin',
                'Nombre : Unique / Multiple',
                'Sac ovulaire : &nbsp; &nbsp; mm, correspond à &nbsp; &nbsp; SA',
                'Placentation : Antérieur / Postérieur / Fundique',
                'Remarque :',
            ])
            .self::section('EMBRYON', [
                'Visible / Non visible',
                'Nombre : Unique / Multiple',
                'Longueur cranio-caudale : &nbsp; &nbsp; mm — correspondant à &nbsp; &nbsp; SA',
                'Diamètre bipariétal : &nbsp; &nbsp; mm — correspondant à &nbsp; &nbsp; SA',
                'Circonférence abdominale : &nbsp; &nbsp; mm — correspondant à &nbsp; &nbsp; SA',
                'Longueur du fémur : &nbsp; &nbsp; mm — correspondant à &nbsp; &nbsp; SA',
                'Activité cardiaque : présente / absente',
                'Mouvements embryo-fœtaux :',
                'Morphologie :',
                'Clarté nucale :',
            ])
            .self::section('CONCLUSION', [
                'Aspect grossesse évolutive / Arrêtée / Évolutive douteuse — correspondant à &nbsp; &nbsp; SA',
                'Date de fécondation probable :',
                'DAP échographique :',
                'Terme le :',
                'Contrôle souhaitable dans &nbsp; &nbsp; jours',
            ])
            .self::freeSection('OBSERVATION')
            .self::freeSection('N.B.');
    }
}
