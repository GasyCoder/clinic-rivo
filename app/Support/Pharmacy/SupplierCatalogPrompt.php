<?php

namespace App\Support\Pharmacy;

/**
 * ADR-241 — « Générer le prompt » : les consignes de la clinique, mot pour mot,
 * pour qu'une autre IA produise un canevas .xlsx qui reproduit le catalogue du
 * fournisseur. La structure lue (SupplierCatalogStructure) n'est servie qu'à
 * l'écran, en résumé.
 */
final class SupplierCatalogPrompt
{
    /**
     * Les consignes, telles que la clinique les a écrites : l'autre IA produit
     * un canevas .xlsx qui reproduit le catalogue joint, sans rien y imposer.
     */
    public const INSTRUCTIONS = <<<'TXT'
Rôle : tu génères un canevas Excel (.xlsx) de saisie qui reproduit exactement la structure du catalogue d'un fournisseur. Le document joint (xlsx, csv, pdf, image ou texte) est la seule source de vérité.

Procédure :
1. Lis le document en entier : toutes les feuilles, toutes les lignes d'en-têtes, les rubriques, les cellules fusionnées, les colonnes sans en-tête.
2. Reproduis la structure telle quelle : mêmes feuilles (même nom), mêmes colonnes, même ordre, mêmes intitulés (orthographe, casse, abréviations, accents). Ne renomme, n'ajoute, ne fusionne et ne supprime rien.
3. Si le document est une liste de produits sans en-têtes clairs, ou si l'écart avec un catalogue classique est important, garde la structure du fournisseur et signale l'écart. Ne le corrige pas.
4. Déduis pour chaque colonne : type (texte, entier, décimal, date, liste), caractère obligatoire (seulement si toujours remplie), valeurs autorisées (seulement si l'ensemble est fermé et connu), format. Si un type ou une règle est incertain, laisse la colonne en texte libre et signale-le.
5. N'invente aucune donnée : pas de valeurs, de devise, d'unité ou de liste que le document ne donne pas.

Fichier à produire (un seul .xlsx, prêt à l'emploi) :
- Une feuille par feuille du fournisseur, avec le même nom.
- Ligne 1 : les intitulés exacts du fournisseur, en gras blanc sur fond #334155.
- Panneau figé sous la ligne 1 (A2).
- Largeur de colonnes adaptée au contenu.
- Lignes 2 à 4 : 3 exemples réalistes tirés du document source (copiés tels quels, pas inventés), dont une ligne partiellement remplie si le source en contient.
- Liste déroulante (validation de données) uniquement pour les colonnes à valeurs fermées. Validation numérique uniquement pour les colonnes entières ou décimales.
- Les rubriques, lignes de titre et cellules fusionnées du fournisseur sont reproduites telles quelles, pas aplaties.
- Une colonne sans en-tête reste sans en-tête.

Réponse : livre uniquement le fichier. Après le fichier, ajoute au maximum 3 lignes de notes pour les écarts constatés et les points incertains, sans les trancher. Aucune explication, aucune introduction.
TXT;

    /**
     * Le prompt est exactement le texte de la clinique : le fichier du
     * fournisseur, joint à l'autre IA, fait foi ; rien n'y est ajouté.
     *
     * @param  array<string, mixed>  $structure
     */
    public static function build(array $structure = []): string
    {
        return self::INSTRUCTIONS;
    }
}
