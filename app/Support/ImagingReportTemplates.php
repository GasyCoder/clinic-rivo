<?php

namespace App\Support;

/**
 * Les feuilles de compte rendu d'échographie de la clinique (ADR-108).
 *
 * Ce sont les formulaires papier de la clinique « RÉSULTATS D'ÉCHOGRAPHIE »,
 * transcrits rubrique par rubrique, dans le même ordre, avec les mêmes mots et
 * la même répartition en deux colonnes. Le médecin en choisit un
 * explicitement : **rien n'est déduit du nom ni du code de l'examen**
 * (ADR-052). « Échographie pelvienne » et « Échographie abdomino-pelvienne » se
 * ressemblent assez pour qu'une correspondance automatique finisse par insérer
 * la mauvaise feuille.
 *
 * Le canevas ne porte que le **corps clinique**. L'en-tête de la clinique, le
 * numéro de dossier, l'identité du patient, « Fait le » et « Le médecin
 * responsable » sont déjà produits par `ImagingReportDocument` à l'impression :
 * les retaper ici les ferait diverger du dossier, et l'ADR-084 pose qu'aucune
 * donnée déjà consignée n'est redemandée.
 *
 * **La mise en page tient à des sauts `<hr>`.** Le corps se lit en régions :
 * la première est la colonne de gauche, la deuxième celle de droite, les
 * suivantes des cases pleine largeur (Conclusion, N.B.). Une feuille dont le
 * médecin a effacé les traits retombe simplement sur une seule région.
 *
 * Les libellés — y compris leurs accents et leurs coquilles — sont ceux du
 * papier : corriger la terminologie d'un document de la clinique n'appartient
 * pas à l'implémentation. Les divergences sont signalées dans l'ADR-108.
 *
 * Le rendu est du HTML restreint aux balises que `ClinicalRichTextSanitizer`
 * accepte — le canevas passe par le même filtre que ce que le médecin tape.
 */
final class ImagingReportTemplates
{
    /**
     * La feuille proposée d'office pour ces examens, avant tout réglage du
     * site. Une **liste explicite par code du catalogue**, jamais un motif :
     * « ECHO-OBS » (obstétricale sans précision), « ECHO-MORPHO » et
     * « ECHO-CARD » ne correspondent à aucune feuille sans ambiguïté, et
     * n'en reçoivent donc aucune (ADR-052). Un site règle les autres depuis la
     * fenêtre de saisie.
     *
     * @var array<string, string>
     */
    public const DEFAULT_BY_EXAM_CODE = [
        'ECHO-ABD' => 'ECHO_ABDOMINALE',
        'ECHO-RENAL' => 'ECHO_RENALE_VESICALE',
        'ECHO-PROSTATE' => 'ECHO_PROSTATIQUE',
        'ECHO-MAMMAIRE' => 'ECHO_MAMMAIRE',
        'ECHO-THYROIDE' => 'ECHO_THYROIDIENNE',
        'ECHO-SCROTALE' => 'ECHO_SCROTALE',
        'ECHO-PARTIES-MOLLES' => 'ECHO_PARTIES_MOLLES',
        'ECHO-ABD-PEL' => 'ECHO_ABDOMINO_PELVIENNE',
        'ECHO-PEL' => 'ECHO_PELVIENNE',
        'ECHO-OBS-T1' => 'ECHO_OBSTETRICALE_T1',
        'ECHO-OBS-T2' => 'ECHO_OBSTETRICALE_T2_T3',
        'ECHO-OBS-T3' => 'ECHO_OBSTETRICALE_T2_T3',
    ];

    /** Espace insécable répété : la place où le médecin écrit une valeur. */
    private const GAP = '&nbsp; &nbsp; &nbsp;';

    /**
     * `title` est l'intitulé du bandeau bleu, tel que le papier l'écrit ;
     * `validated` distingue les feuilles papier de la clinique des
     * **propositions** du système, écrites faute de modèle et à faire valider
     * par un médecin avant usage courant.
     *
     * @return array<int, array{key: string, label: string, title: string, description: string, body_html: string, validated: bool}>
     */
    public static function all(): array
    {
        return [
            self::sheet('ECHO_ABDOMINO_PELVIENNE', 'Échographie abdomino-pelvienne', 'ÉCHOGRAPHIE ABDOMINO-PELVIENNE',
                'Foie, reins, pancréas, rate, vessie, utérus, ovaires, cul de sac de Douglas', self::abdominoPelvienne()),
            self::sheet('ECHO_ABDOMINO_PELVIENNE_PROSTATE', 'Échographie abdomino-pelvienne (avec prostate)', 'ÉCHOGRAPHIE ABDOMINO-PELVIENNE',
                'Même feuille que l’abdomino-pelvienne, avec la rubrique Prostate à la place du N.B.', self::abdominoPelvienne(withProstate: true)),
            self::sheet('ECHO_PELVIENNE', 'Échographie pelvienne', 'ÉCHOGRAPHIE PELVIENNE',
                'Utérus, ovaires, autres organes pelviens, conclusion', self::pelvienne()),
            self::sheet('ECHO_OBSTETRICALE_T1', 'Échographie obstétricale (1er trimestre)', 'ÉCHOGRAPHIE OBSTETRICALE (1er TRIMESTRE)',
                'Utérus, ovaires, sac ovulaire, embryon, conclusion', self::obstetricaleT1()),
            self::sheet('ECHO_OBSTETRICALE_T2_T3', 'Échographie obstétricale (2e – 3e trimestre)', 'ÉCHOGRAPHIE OBSTÉTRICALE (2ème – 3ème TRIMESTRE)',
                'Fœtus, physiologie, liquide amniotique, cordon, placenta, conclusion', self::obstetricaleT2T3()),

            // Propositions : la clinique n'a pas fourni de modèle papier.
            ...self::proposals(),
        ];
    }

    /** @return array{key: string, label: string, title: string, description: string, body_html: string, validated: bool} */
    private static function sheet(string $key, string $label, string $title, string $description, string $body, bool $validated = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'title' => $title,
            'description' => $description,
            'body_html' => $body,
            'validated' => $validated,
        ];
    }

    /**
     * Les feuilles **proposées** faute de modèle papier (ADR-108).
     *
     * Elles ne portent que des rubriques à compléter — jamais une valeur, une
     * norme ou un seuil — et reprennent les rubriques des feuilles papier
     * quand l'organe y figure déjà (foie, reins, pancréas, rate, vessie,
     * prostate). Ce sont des propositions du système : un médecin de la
     * clinique doit les relire, et un site peut les recopier en feuille à lui
     * (« + ») pour les corriger.
     *
     * @return array<int, array{key: string, label: string, title: string, description: string, body_html: string, validated: bool}>
     */
    private static function proposals(): array
    {
        $doppler = 'Vascularisation (Doppler) :';
        $conclusion = self::section('CONCLUSION :').self::room(2);
        $nb = self::section('N.B. :').self::room();

        return [
            self::sheet('ECHO_ABDOMINALE', 'Échographie abdominale (proposition)', 'ÉCHOGRAPHIE ABDOMINALE',
                'Foie, pancréas, rate, reins, vessie — rubriques de la feuille abdomino-pelvienne',
                self::layout(
                    self::section('FOIE', [
                        'Échostructure:', 'Bord: '.self::GAP.' Contours:', 'Flèche hépatique droite:', 'Tronc porte :',
                        'Canaux biliaires :', 'Vésicule biliaire :', 'Espace de Morisson :',
                    ])
                    .self::section('PANCRÉAS :', ['Echostructure :', 'Mesure : '.self::GAP.' tête - queue - isthme'])
                    .self::section('RATE :', ['Échostructure :', 'Grand axe mesure :', 'Diamètre transverse maximum :']),
                    self::section('REIN DROIT :', ['Diamètre :', 'Epaisseur de la Parenchyme mesure :', 'Différenciation parenchymo-sinusale :', 'Lithiase :'])
                    .self::section('REIN GAUCHE :', ['Diamètre :', 'Parenchyme mesure :', 'Différenciation parenchymo-sinusale :', 'Lithiase :'])
                    .self::section('VESSIE :', ['Echostructure :', 'Paroi : '.self::GAP.' Lithiase :']),
                    $conclusion,
                ), false),

            self::sheet('ECHO_RENALE_VESICALE', 'Échographie rénale et vésicale (proposition)', 'ÉCHOGRAPHIE RÉNALE ET VÉSICALE',
                'Reins et vessie — rubriques de la feuille abdomino-pelvienne',
                self::layout(
                    self::section('REIN DROIT :', ['Diamètre :', 'Epaisseur de la Parenchyme mesure :', 'Différenciation parenchymo-sinusale :', 'Cavités pyélocalicielles :', 'Lithiase :']),
                    self::section('REIN GAUCHE :', ['Diamètre :', 'Parenchyme mesure :', 'Différenciation parenchymo-sinusale :', 'Cavités pyélocalicielles :', 'Lithiase :'])
                    .self::section('VESSIE :', ['Echostructure :', 'Paroi : '.self::GAP.' Lithiase :']),
                    $conclusion,
                ), false),

            self::sheet('ECHO_PROSTATIQUE', 'Échographie prostatique (proposition)', 'ÉCHOGRAPHIE PROSTATIQUE',
                'Prostate et vessie — rubriques de la feuille abdomino-pelvienne',
                self::layout(
                    self::section('PROSTATE :', ['Mesure: '.self::GAP.' Volume:', 'Echostructure :', 'Contours :']),
                    self::section('VESSIE :', ['Echostructure :', 'Paroi : '.self::GAP.' Lithiase :']),
                    $conclusion,
                ), false),

            self::sheet('ECHO_MAMMAIRE', 'Échographie mammaire (proposition)', 'ÉCHOGRAPHIE MAMMAIRE',
                'Sein droit, sein gauche, ganglions axillaires, conclusion',
                self::layout(
                    self::section('SEIN DROIT :', ['Parenchyme (échostructure) :', 'Lésion(s) : siège / taille (mm) :', 'Forme / contours :', 'Échogénicité :', $doppler, 'Ganglions axillaires :']),
                    self::section('SEIN GAUCHE :', ['Parenchyme (échostructure) :', 'Lésion(s) : siège / taille (mm) :', 'Forme / contours :', 'Échogénicité :', $doppler, 'Ganglions axillaires :']),
                    self::section('CONCLUSION :', ['Classification ACR BI-RADS :']).self::room(2),
                    $nb,
                ), false),

            self::sheet('ECHO_THYROIDIENNE', 'Échographie thyroïdienne (proposition)', 'ÉCHOGRAPHIE THYROÏDIENNE',
                'Lobes, isthme, nodules, aires ganglionnaires, conclusion',
                self::layout(
                    self::section('LOBE DROIT :', ['Dimensions / volume :', 'Échostructure :', 'Nodule(s) : siège / taille (mm) :', 'Contours / échogénicité :', $doppler])
                    .self::section('ISTHME :', ['Épaisseur :']),
                    self::section('LOBE GAUCHE :', ['Dimensions / volume :', 'Échostructure :', 'Nodule(s) : siège / taille (mm) :', 'Contours / échogénicité :', $doppler])
                    .self::section('AIRES GANGLIONNAIRES CERVICALES :').self::room(),
                    $conclusion,
                    $nb,
                ), false),

            self::sheet('ECHO_SCROTALE', 'Échographie scrotale (proposition)', 'ÉCHOGRAPHIE SCROTALE',
                'Testicules, épididymes, enveloppes, conclusion',
                self::layout(
                    self::section('TESTICULE DROIT :', ['Dimensions / volume :', 'Échostructure :', 'Lésion focale :', $doppler])
                    .self::section('ÉPIDIDYME DROIT :', ['Aspect / taille :']),
                    self::section('TESTICULE GAUCHE :', ['Dimensions / volume :', 'Échostructure :', 'Lésion focale :', $doppler])
                    .self::section('ÉPIDIDYME GAUCHE :', ['Aspect / taille :']),
                    self::section('ENVELOPPES / CORDONS :', ['Hydrocèle :', 'Varicocèle :']).self::room(),
                    $conclusion,
                ), false),

            self::sheet('ECHO_PARTIES_MOLLES', 'Échographie des parties molles (proposition)', 'ÉCHOGRAPHIE DES PARTIES MOLLES',
                'Région explorée, lésion, structures adjacentes, conclusion',
                self::layout(
                    self::section('RÉGION EXPLORÉE :', ['Siège :', 'Côté :'])
                    .self::section('LÉSION :', ['Taille (mm) :', 'Profondeur :', 'Forme / contours :', 'Échostructure :', $doppler]),
                    self::section('STRUCTURES ADJACENTES :', ['Peau / tissu sous-cutané :', 'Muscles / tendons :', 'Rapports (aponévrose, os) :', 'Ganglions :']),
                    $conclusion,
                    $nb,
                ), false),
        ];
    }

    /**
     * Une rubrique : son titre, puis ses lignes à puces. Une ligne est un
     * texte, ou `[texte, [sous-lignes]]` pour les sous-points « o » du papier
     * — une liste dans la liste, plutôt qu'un caractère « o » qui ne se relit
     * pas.
     *
     * @param  array<int, string|array{0: string, 1: array<int, string>}>  $lines
     */
    private static function section(string $title, array $lines = []): string
    {
        $html = '<p><strong>'.$title.'</strong></p>';

        if ($lines === []) {
            return $html;
        }

        return $html.self::list($lines);
    }

    /** @param  array<int, string|array{0: string, 1: array<int, string>}>  $lines */
    private static function list(array $lines): string
    {
        $items = array_map(static function (string|array $line): string {
            if (is_string($line)) {
                return '<li>'.$line.'</li>';
            }

            return '<li>'.$line[0].self::list($line[1]).'</li>';
        }, $lines);

        return '<ul>'.implode('', $items).'</ul>';
    }

    /** Une ligne vide où écrire : le papier y laisse de la place. */
    private static function room(int $lines = 1): string
    {
        return str_repeat('<p><br></p>', $lines);
    }

    /** Colonne de gauche, colonne de droite, puis les cases pleine largeur. */
    private static function layout(string $left, string $right, string ...$wide): string
    {
        return implode('<hr>', [$left, $right, ...$wide]);
    }

    /**
     * Le papier existe en deux versions. L'une porte un N.B. dans la colonne
     * de droite et écrit « Diamètre bipariétal » sur les reins ; l'autre porte
     * une rubrique PROSTATE à la place et écrit « Diamètre: ». Les deux sont
     * reprises telles quelles : la clinique n'a pas tranché entre elles.
     */
    private static function abdominoPelvienne(bool $withProstate = false): string
    {
        $kidneyDiameter = $withProstate ? 'Diamètre:' : 'Diamètre bipariétal :';

        $left = self::section('FOIE', [
            'Échostructure:',
            'Bord: '.self::GAP.' Contours:',
            'Flèche hépatique droite:',
            'Tronc porte :',
            'Canaux biliaires :',
            'Vésicule biliaire :',
            'Espace de Morisson :',
        ])
            .self::section('REIN DROIT :', [
                $kidneyDiameter,
                'Epaisseur de la Parenchyme mesure :',
                'Différenciation parenchymo-sinusale :',
                'Lithiase:',
            ])
            .self::section('REIN GAUCHE :', [
                $kidneyDiameter,
                'Parenchyme mesure :',
                'Différenciation parenchymo-sinusale :',
                'Lithiase :',
            ])
            .self::section('PANCRÉAS :', [
                'Echostructure :',
                'Mesure : '.self::GAP.' tête - queue - isthme',
            ])
            .self::section('RATE :', [
                'Échostructure :',
                'Grand axe mesure :',
                'Diamètre transverse maximum :',
            ]);

        $right = self::section('VESSIE :', [
            'Echostructure :',
            'Paroi : '.self::GAP.' Lithiase :',
        ])
            .self::section('UTERUS :', [
                'Echostructure :',
                'Position :',
                'Epaisseur de l’endomètre :',
                'Mesure :',
                'Myome :',
            ])
            .self::section('OVAIRE DROIT :', [
                'Echostructure :',
                'Mesure : '.self::GAP.' Volume :',
                'Follicules :',
            ])
            .self::section('OVAIRE GAUCHE :', [
                'Echostructure :',
                'Mesure : '.self::GAP.' Volume :',
                'Follicules :',
            ])
            .self::section('CUL DE SAC DE DOUGLAS :').self::room()
            .($withProstate
                ? self::section('PROSTATE :', ['Mesure: '.self::GAP.' Volume:'])
                : self::section('N.B. :').self::room());

        return self::layout($left, $right, self::section('CONCLUSION :').self::room(2));
    }

    private static function pelvienne(): string
    {
        $left = self::section('UTERUS', [
            'Orientation: Antéversé / Intermédiaire / Retroversé',
            ['Volume', [
                'Longueur: '.self::GAP.' mm',
                'Epaisseur: '.self::GAP.' mm',
                'Largeur: '.self::GAP.' mm',
                'Normale / Hyertrophié',
            ]],
            'Contour: Régulier / Irrégulier',
            'Myomètre: Echo structure homogéne/Inhomogénes',
            'Endomètre: Echogène / hypoéchogène',
            'Epaisseur: '.self::GAP.' mm',
            'Echocavitaire: Linéaire / Autre:',
        ])
            .self::room()
            .self::section('OVAIRES:', [
                ['Droite:', ['Taille:', 'Echostructure:']],
                ['Gauche:', ['Taille:', 'Echostructure:']],
                ['Autres organes pelviens:', [
                    'Vessie',
                    'Cul de sac de Douglas :',
                    'Masse pelvienne :',
                    'Autres :',
                ]],
            ]);

        // Sur le papier, la conclusion occupe toute la hauteur de la colonne
        // de droite, et le N.B. la pleine largeur en dessous.
        return self::layout(
            $left,
            self::section('CONCLUSION :').self::room(3),
            self::section('N.B. :').self::room(2),
        );
    }

    private static function obstetricaleT1(): string
    {
        // Le papier coupe la rubrique EMBRYON entre les deux colonnes : la
        // colonne de droite reprend les mesures sans nouveau titre.
        $left = self::section('UTERUS', [
            'Antéversé / Intermédiaire / Retraversé (Fléchi)',
            'Dimension :',
            'Remarque :',
        ])
            .self::room()
            .self::section('OVAIRES', [
                ['Droite:', ['Taille:', 'Echostructure:']],
                ['Gauche:', ['Taille:', 'Echostructure:']],
            ])
            .self::room()
            .self::section('SAC OVULAIRE', [
                'Intra utérin / Extra utérin',
                'Nombre: Unique / Multiple',
                'Sac ovulaire: '.self::GAP.' mm, correspond à '.self::GAP.' SA',
                'Placentation : Antérieur / postérieur / Fundique',
                'Remarque :',
            ])
            .self::room()
            .self::section('EMBRYON', [
                'Visible / Non Visible',
                'Nombre: Unique / Multiple',
            ]);

        $right = self::list([
            'Longueur Cranio-caudale '.self::GAP.' mm — Correspondant à '.self::GAP.' SA',
            'Diamètre bipariétal '.self::GAP.' mm — Correspondant à '.self::GAP.' SA',
            'Circonférence abdominale '.self::GAP.' mm — Correspondant à '.self::GAP.' SA',
            'Longueur du fémur '.self::GAP.' mm — Correspondant à '.self::GAP.' SA',
            'Activité cardiaque : présente / absente',
            'Mouvement embryo-foetaux :',
            'Morphologie :',
            'Clarté nucale :',
        ])
            .self::room()
            .self::section('CONCLUSION', [
                'Aspect grosse évolutive / Arrêté / Evolutive douteuse '.self::GAP.' Correspondant à '.self::GAP.' SA',
                'Date de fécondation probable :',
                'DAP Echographique :',
                'Terme le :',
                'Contrôle souhaitable dans '.self::GAP.' jours',
            ])
            .self::room()
            .self::section('OBSERVATION').self::room();

        return self::layout($left, $right, self::section('N.B. :').self::room(2));
    }

    private static function obstetricaleT2T3(): string
    {
        $left = self::section('FOETUS:', [
            'Grossesse: mono foetale/multiple',
            ['Présentation:', ['Céphalique', 'Siège', 'Transversale', 'Oblique']],
            ['Biométrie', [
                'Diamètre bi-pariétale: '.self::GAP.' mm',
                'Percentile de '.self::GAP.' SA',
                'Circonférence de la tête',
                'FL',
                'CA',
                'Autre',
            ]],
            'POIDS FOETAL ESTIME (PFE: '.self::GAP.' g(±10%)',
            ['MORPHOLOGIE', ['Pôle céphalique', 'Thorax', 'Abdomen', 'Membre']],
        ])
            .self::room()
            .self::section('PHYSIOLOGIE:', [
                'AC',
                'MAF',
                'Movement respiratoire:',
            ])
            .self::room()
            .self::section('VOLUME DE LIQUIDE AMNIOTIQUE:', [
                'Normale (Index amniotique : '.self::GAP.' )',
                'Hydramnios (IA: '.self::GAP.' )',
                'Oligoamnios (IA : '.self::GAP.' )',
            ]);

        $right = self::section('CORDON OMBILICAL', [
            'Circulaire : ☐ Oui &nbsp; ☐ Non',
            ['Vaisseaux :', ['Artère :', 'Veine :']],
        ])
            .self::room()
            .self::section('INSERTION PLACENTAIRE:', [
                ['Localisation :', ['Antérieur', 'Postérieur', 'Fundique']],
                'Noramale / Basse',
                'Echo structure:',
                'Grade: '.self::GAP.' /3',
            ])
            .self::room()
            .self::section('CONCLUSION :').self::room(3)
            .self::section('VITALITE FOETAL :').self::room();

        return self::layout($left, $right, self::section('N.B :').self::room(2));
    }
}
