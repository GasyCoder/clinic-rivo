/**
 * ADR-212 — lecture des bonus du mois à l'écran. Rien n'y décide : valider
 * recompte sur le serveur. Ces fonctions ne font que dire où en est une ligne
 * et nommer un mois.
 */

/**
 * Où en est le bonus d'une personne ce mois-ci.
 *
 * `PAID` versé · `VALIDATED` validé, à verser · `TO_VALIDATE` seuil atteint,
 * rien de validé · `BELOW` seuil pas atteint. Un bonus annulé ne compte pas :
 * seul le bonus en cours (`award`) décide.
 */
export function bonusRowState(row) {
    if (row?.award?.status === 'PAID') return 'PAID';
    if (row?.award?.status === 'VALIDATED') return 'VALIDATED';
    if (row?.reached) return 'TO_VALIDATE';

    return 'BELOW';
}

// Nommer et parcourir un mois : écrit une fois dans utilities/date.js.
export { monthLabel, shiftMonth } from './date.js';

/**
 * État de la case « Tout sélectionner » sur la liste affichée (filtrée par la
 * recherche) : `true` si toute la liste est cochée, `'indeterminate'` si une
 * partie seulement, `false` sinon — et sur une liste vide.
 */
export function selectionState(selected, shown) {
    const chosen = shown.filter((uuid) => selected.includes(uuid)).length;

    if (! shown.length || chosen === 0) return false;

    return chosen === shown.length ? true : 'indeterminate';
}

/**
 * Coche ou décoche d'un geste la liste affichée. Une liste déjà toute cochée
 * se décoche ; sinon elle se coche en entier. Ce qui est choisi hors de la
 * liste affichée (masqué par la recherche) n'est jamais touché.
 */
export function toggleShown(selected, shown) {
    if (selectionState(selected, shown) === true) return selected.filter((uuid) => ! shown.includes(uuid));

    return [...selected, ...shown.filter((uuid) => ! selected.includes(uuid))];
}
