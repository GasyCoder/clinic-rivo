import { currencyFormat } from '../lib/siteSettings.js';

/**
 * Un montant en Ariary, écrit comme le site l'a réglé (ADR-184) : « Ar »,
 * « Ariary » ou « MGA », avant ou après le nombre. Seule l'écriture change :
 * aucun montant n'est converti.
 *
 * Les décimales ne cachent jamais rien : « seulement si nécessaire » (0) écrit
 * 12 500 et 4 500,50 ; « toujours deux » (2) écrit 12 500,00. Arrondir un prix
 * d'achat à l'entier l'afficherait faux.
 *
 * Le second argument — la devise enregistrée sur un tarif — est accepté pour
 * les appelants existants ; toutes les devises enregistrées sont en MGA.
 */
export function formatMoney(value, _currency = 'MGA', format = currencyFormat()) {
    const number = new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: format.decimals,
        maximumFractionDigits: 2,
    }).format(Number(value ?? 0));

    return format.position === 'before' ? `${format.label} ${number}` : `${number} ${format.label}`;
}

/** Le libellé seul, pour l'unité affichée au bout d'un champ de saisie. */
export const currencyLabel = () => currencyFormat().label;
