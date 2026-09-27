/**
 * ADR-181, amendement du 2026-09-24 — ce qui se compare, et par famille.
 *
 * Une ligne du comparateur porte les offres de chaque fournisseur pour un
 * même produit. Beaucoup ne sont proposés que par un seul fournisseur : on ne
 * les compare pas, on les commande. Chaque produit tombe donc dans une seule
 * case — « chez plusieurs fournisseurs » ou « seulement chez X » — et la somme
 * des cases fait le total. La famille évite de parcourir la liste de A à Z.
 */

export const NO_FAMILY = 'Sans famille';
export const SEVERAL = 'SEVERAL';

/** Les fournisseurs qui proposent ce produit, chacun une fois. */
export const suppliersOf = (row) => [...new Set((row.quotes ?? []).map((quote) => quote.supplier_uuid))];

export const familyOf = (row) => row.family || NO_FAMILY;

/** « SEVERAL » dès deux fournisseurs, sinon « ONLY:<uuid> » du seul qui le propose. */
export const coverageOf = (row) => {
    const quoting = suppliersOf(row);

    return quoting.length > 1 ? SEVERAL : `ONLY:${quoting[0] ?? ''}`;
};

/** Les familles par ordre alphabétique ; « Sans famille » ferme la marche. */
export const compareFamilies = (first, second) => (first === NO_FAMILY) - (second === NO_FAMILY)
    || first.localeCompare(second, 'fr');

/** Combien de lignes par clé : ce que donnerait un clic sur chaque case. */
export const countBy = (rows, keyOf) => rows.reduce(
    (counts, row) => counts.set(keyOf(row), (counts.get(keyOf(row)) ?? 0) + 1),
    new Map(),
);

/** Les lignes rangées sous leur famille, dans l'ordre des familles. */
export const groupByFamily = (rows) => [...rows.reduce((groups, row) => {
    const label = familyOf(row);
    if (!groups.has(label)) groups.set(label, []);
    groups.get(label).push(row);

    return groups;
}, new Map()).entries()]
    .sort(([first], [second]) => compareFamilies(first, second))
    .map(([label, groupRows]) => ({ label, rows: groupRows }));
