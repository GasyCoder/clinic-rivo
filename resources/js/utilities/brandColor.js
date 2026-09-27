/**
 * La couleur principale d'un site et sa lisibilité (ADR-184), pour l'aperçu du
 * portail. Le thème réellement appliqué est calculé côté serveur
 * (`App\Services\Settings\ThemeColor`) ; ici on dit seulement, avant
 * d'enregistrer, si un texte blanc restera lisible dessus.
 */
export const DEFAULT_PRIMARY = '#287D9F';

/** Des teintes éprouvées, toutes lisibles en blanc (contraste ≥ 4,5:1). */
export const PRIMARY_PRESETS = Object.freeze([
    { value: '#287D9F', label: 'Bleu RIVO' },
    { value: '#0F766E', label: 'Sarcelle' },
    { value: '#15803D', label: 'Vert' },
    { value: '#1D4ED8', label: 'Bleu roi' },
    { value: '#4338CA', label: 'Indigo' },
    { value: '#7E22CE', label: 'Violet' },
    { value: '#BE123C', label: 'Framboise' },
    { value: '#C2410C', label: 'Orange brûlé' },
    { value: '#334155', label: 'Ardoise' },
]);

export const isHexColor = (value) => /^#[0-9a-fA-F]{6}$/.test(String(value ?? ''));

const channel = (value) => {
    const c = value / 255;

    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};

export const relativeLuminance = (hex) => {
    const [r, g, b] = [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16));

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
};

export const contrastRatio = (a, b) => (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);

/** Le contraste d'un texte blanc sur la couleur, et ce qu'il permet. */
export const readability = (hex) => {
    if (! isHexColor(hex)) return null;

    const ratio = contrastRatio(relativeLuminance(hex), 1);

    return {
        ratio,
        label: `${ratio.toFixed(1).replace('.', ',')}:1`,
        // 4,5:1 : texte courant ; 3:1 : gros texte et icônes seulement.
        level: ratio >= 4.5 ? 'good' : ratio >= 3 ? 'large' : 'poor',
    };
};
