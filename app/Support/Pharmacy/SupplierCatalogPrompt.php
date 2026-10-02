<?php

namespace App\Support\Pharmacy;

/**
 * ADR-241 — « Générer le prompt » : les consignes de la clinique pour qu'une
 * autre IA produise un canevas .xlsx qui reproduit le catalogue joint, suivies
 * des repères que RIVO a lus dans ce fichier (SupplierCatalogStructure).
 *
 * Le document du fournisseur fait foi, et la structure d'un autre système ne
 * s'impose pas. Déterministe : le même fichier donne le même prompt.
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
2. Reproduis la structure telle quelle : mêmes feuilles (même nom), mêmes colonnes, même ordre, mêmes intitulés (orthographe, casse, abréviations, accents). Ne renomme, n'ajoute, ne fusionne et ne supprime rien. N'impose la structure d'aucun autre système.
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

    /** @param  array<string, mixed>  $structure */
    public static function build(array $structure): string
    {
        $file = $structure['file'] ?? [];
        $lines = [self::INSTRUCTIONS, '', '---', ''];

        // Ce que RIVO a lu : une aide pour vérifier, jamais une consigne de plus.
        $lines[] = 'Repères lus dans le document joint (à vérifier sur le document ; en cas d’écart, le document fait foi) :';
        $lines[] = '';
        $lines[] = '- Fournisseur : '.($file['supplier'] ?? 'non renseigné');
        $lines[] = '- Fichier : '.($file['name'] ?? 'non renseigné').' ('.($file['kind_label'] ?? '?').')';

        if (! empty($file['catalog_date'])) {
            $lines[] = '- Date du catalogue : '.$file['catalog_date'];
        }

        if (($structure['readable'] ?? false) !== true) {
            $lines[] = '';
            $lines[] = (string) ($structure['reason'] ?? 'Le contenu du fichier n’a pas pu être lu.');
            $lines[] = 'Lis les colonnes dans le document joint lui-même : ne suppose aucune colonne.';
        } else {
            $lines[] = '- Feuilles : '.($file['sheet_count'] ?? count($structure['sheets'] ?? []));

            foreach ($structure['sheets'] ?? [] as $number => $sheet) {
                array_push($lines, ...self::sheet($sheet, $number + 1));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @return array<int, string>
     */
    private static function sheet(array $sheet, int $number): array
    {
        $lines = ['', "### Feuille {$number} — « {$sheet['name']} »", ''];

        if (($sheet['columns'] ?? []) === []) {
            $lines[] = (string) ($sheet['note'] ?? 'Feuille sans colonnes reconnues.');

            return $lines;
        }

        if (! empty($sheet['preamble'])) {
            $lines[] = '- Texte au-dessus des en-têtes : « '.implode(' » ; « ', array_slice($sheet['preamble'], 0, 5)).' »';
        }

        $lines[] = '- Ligne des en-têtes : '.$sheet['header_row'];
        $lines[] = '- Lignes de données : '.$sheet['data_rows'].(! empty($sheet['truncated']) ? ' (fichier plus long : les 5 000 premières lignes ont été lues)' : '');
        $lines[] = '';
        $lines[] = '| Col. | Intitulé du fournisseur | Type observé | Remplie | Valeurs distinctes | Exemples |';
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($sheet['columns'] as $column) {
            $header = $column['header'] !== '' ? $column['header'] : '(sans en-tête)';
            $filled = $column['required'] ? 'toujours' : "{$column['filled']} / ".($column['filled'] + $column['empty']);
            $lines[] = '| '.$column['letter'].' | '.self::cell($header).' | '.$column['type'].' | '.$filled.' | '.$column['distinct'].($column['unique'] ? ' (toutes différentes)' : '').' | '.self::cell(implode(' ; ', $column['samples'])).' |';
        }

        $detailed = array_filter($sheet['columns'], fn (array $column) => $column['options'] !== null || $column['range'] !== null);

        if ($detailed !== []) {
            $lines[] = '';
            $lines[] = 'Précisions par colonne :';

            foreach ($detailed as $column) {
                $name = $column['header'] !== '' ? $column['header'] : 'colonne '.$column['letter'];

                if ($column['options'] !== null) {
                    $lines[] = '- « '.$name.' » ne prend que ces valeurs : '.implode(' ; ', $column['options']).'.';
                }

                if ($column['range'] !== null) {
                    $lines[] = '- « '.$name.' » va de '.self::number($column['range']['min']).' à '.self::number($column['range']['max']).'.';
                }
            }
        }

        if (! empty($sheet['sections'])) {
            $lines[] = '';
            $lines[] = 'Lignes de rubrique (une seule cellule remplie, elles regroupent les lignes qui suivent) :';

            foreach ($sheet['sections'] as $section) {
                $lines[] = '- ligne '.$section['row'].', colonne '.$section['column'].' : « '.$section['label'].' »'.($section['bold'] ? ' (en gras)' : '');
            }
        }

        if (! empty($sheet['merged_ranges'])) {
            $lines[] = '';
            $lines[] = 'Cellules fusionnées : '.implode(', ', $sheet['merged_ranges']).'.';
        }

        return $lines;
    }

    private static function cell(string $value): string
    {
        return str_replace(['|', "\n"], ['/', ' '], $value);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
    }
}
