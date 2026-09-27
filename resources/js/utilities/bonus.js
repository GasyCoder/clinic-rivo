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
