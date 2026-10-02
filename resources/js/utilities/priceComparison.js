/**
 * ADR-242 — le moins cher, le plus cher et ce qui est entre les deux, sur une
 * ligne du comparateur des fournisseurs. Les réglages sont ceux du compte
 * (App\Support\Pharmacy\PriceComparisonPreferences, même liste).
 */

export const PRICE_COMPARISON_DEFAULTS = Object.freeze({
    best_color: '#059669',
    middle_color: '#D97706',
    worst_color: '#DC2626',
    color_middle: true,
    style: 'TINT',
    show_gap: 'PERCENT',
    min_gap_percent: 0,
    show_labels: true,
    sort_by_price: true,
    show_legend: true,
});

export const PRICE_STYLES = [
    { value: 'TINT', label: 'Carte teintée', description: 'Fond et bordure de la couleur.' },
    { value: 'BORDER', label: 'Bordure', description: 'Seule la bordure prend la couleur.' },
    { value: 'TEXT', label: 'Texte', description: 'Seul le prix prend la couleur.' },
];

export const PRICE_GAPS = [
    { value: 'PERCENT', label: 'En %', example: '+12 %' },
    { value: 'AMOUNT', label: 'En Ariary', example: '+3 600 Ar' },
    { value: 'BOTH', label: 'Les deux', example: '+3 600 Ar · +12 %' },
    { value: 'NONE', label: 'Aucun', example: '—' },
];

/** Les réglages, complétés des valeurs d'origine. */
export function resolvePriceComparison(settings) {
    return { ...PRICE_COMPARISON_DEFAULTS, ...Object.fromEntries(Object.entries(settings ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== '')) };
}

const priceOf = (quote) => (quote?.price === null || quote?.price === undefined || quote.price === '' ? null : Number(quote.price));

/**
 * Le rang d'une offre parmi celles de sa ligne : `best`, `worst`, `middle`, ou
 * `null` quand il n'y a rien à comparer (un seul prix, ou tous égaux).
 * `worst` n'est retenu que si l'écart au moins cher atteint le seuil réglé ;
 * en deçà, le plus cher se lit comme un prix intermédiaire.
 */
export function priceTier(quotes, quote, settings = PRICE_COMPARISON_DEFAULTS) {
    const price = priceOf(quote);
    const prices = (quotes ?? []).map(priceOf).filter((value) => value !== null && Number.isFinite(value));

    if (price === null || prices.length < 2) return { tier: null, gapAmount: 0, gapPercent: 0 };

    const min = Math.min(...prices);
    const max = Math.max(...prices);
    const gapAmount = price - min;
    const gapPercent = min > 0 ? (gapAmount / min) * 100 : 0;

    if (min === max) return { tier: null, gapAmount: 0, gapPercent: 0 };
    if (price === min) return { tier: 'best', gapAmount: 0, gapPercent: 0 };

    const threshold = Number(settings.min_gap_percent) || 0;
    const tier = price === max && gapPercent >= threshold ? 'worst' : 'middle';

    return { tier, gapAmount, gapPercent };
}

/** L'écart affiché sous un prix, selon le réglage, ou une chaîne vide. */
export function gapLabel(tierInfo, settings, formatMoney) {
    if (!tierInfo?.tier || tierInfo.tier === 'best' || settings.show_gap === 'NONE') return '';

    const percent = `+${Math.round(tierInfo.gapPercent).toLocaleString('fr-FR')} %`;
    const amount = `+${formatMoney(tierInfo.gapAmount)}`;

    if (settings.show_gap === 'AMOUNT') return amount;
    if (settings.show_gap === 'BOTH') return `${amount} · ${percent}`;

    return percent;
}

/** La couleur d'un rang, ou null quand il ne se colore pas. */
export function tierColor(tier, settings) {
    if (tier === 'best') return settings.best_color;
    if (tier === 'worst') return settings.worst_color;
    if (tier === 'middle' && settings.color_middle) return settings.middle_color;

    return null;
}

/** Le style en ligne d'une carte d'offre et de son prix. */
export function tierStyles(tier, settings) {
    const color = tierColor(tier, settings);

    if (!color) return { card: {}, price: {} };

    const price = { color, fontWeight: tier === 'middle' ? 600 : 700 };

    if (settings.style === 'TEXT') return { card: {}, price };
    if (settings.style === 'BORDER') return { card: { borderColor: color, borderWidth: '2px' }, price };

    return { card: { borderColor: color, backgroundColor: `${color}1A` }, price };
}

/** Les offres d'une ligne, du moins cher au plus cher (sans prix à la fin), si réglé. */
export function orderQuotes(quotes, settings) {
    if (!settings.sort_by_price) return quotes ?? [];

    return [...(quotes ?? [])].sort((first, second) => {
        const a = priceOf(first);
        const b = priceOf(second);

        if (a === null && b === null) return 0;
        if (a === null) return 1;
        if (b === null) return -1;

        return a - b;
    });
}
