/**
 * Les paramètres du site dont le JavaScript a besoin hors d'un composant
 * (ADR-184) : le nom de l'application pour le titre de l'onglet, et la façon
 * d'écrire l'Ariary pour `formatMoney`.
 *
 * Mis à jour à l'ouverture de l'application et à chaque visite, depuis les
 * props partagées `site` — jamais depuis le stockage du navigateur. Les mêmes
 * valeurs sont posées pour le rendu serveur : un site n'a qu'un jeu de
 * paramètres, donc aucun écart d'hydratation.
 */
export const DEFAULT_BRAND = 'Clinique Saint Georges';

export const DEFAULT_CURRENCY = Object.freeze({ label: 'Ar', position: 'after', decimals: 0 });

const state = {
    brand: DEFAULT_BRAND,
    currency: { ...DEFAULT_CURRENCY },
};

export const applySiteSettings = (site) => {
    if (! site) return;

    state.brand = String(site.brand ?? '').trim() || DEFAULT_BRAND;

    const currency = site.currency ?? {};
    state.currency = {
        label: ['Ar', 'Ariary', 'MGA'].includes(currency.label) ? currency.label : DEFAULT_CURRENCY.label,
        position: currency.position === 'before' ? 'before' : 'after',
        decimals: Number(currency.decimals) === 2 ? 2 : 0,
    };
};

export const siteBrand = () => state.brand;

export const currencyFormat = () => state.currency;
