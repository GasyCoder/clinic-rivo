/**
 * ADR-191 — les réglages « Avancé » appliqués à la page : taille du texte,
 * densité, arrondis, animations et contraste. Le serveur les pose sur <html> au
 * premier rendu ; ceci les réapplique quand l'utilisateur les change, sans
 * recharger la page. Une taille en %, jamais en px : la taille choisie dans le
 * navigateur reste respectée.
 */
export const DEFAULT_FONT_SIZE = 16;

export const FONT_SIZES = [14, 15, 16, 17, 18];

export const DENSITIES = [
    { value: 'compact', label: 'Compacte', hint: 'Champs et boutons plus bas.' },
    { value: 'default', label: 'Normale', hint: 'Comme avant.' },
    { value: 'comfortable', label: 'Aérée', hint: 'Plus de place pour viser au doigt.' },
];

export const RADII = [
    { value: 'square', label: 'Droits' },
    { value: 'default', label: 'Normaux' },
    { value: 'round', label: 'Arrondis' },
];

export const MOTIONS = [
    { value: 'system', label: 'Système', hint: 'Suit le réglage de l’appareil.' },
    { value: 'reduce', label: 'Réduites', hint: 'Ni glissements ni fondus.' },
    { value: 'full', label: 'Complètes', hint: 'Toujours animées.' },
];

export const CONTRASTS = [
    { value: 'standard', label: 'Standard' },
    { value: 'high', label: 'Élevé' },
    { value: 'max', label: 'Maximal' },
];

export const fontSizePercent = (size) => `${Math.round((Number(size) / DEFAULT_FONT_SIZE) * 100000) / 1000}%`;

export function applyAppearance(effective, root = typeof document !== 'undefined' ? document.documentElement : null) {
    if (! root || ! effective) return;

    root.dataset.density = effective.density ?? 'default';
    root.dataset.radius = effective.radius ?? 'default';
    root.dataset.motion = effective.motion ?? 'system';
    root.dataset.contrast = effective.contrast ?? 'standard';
    root.style.fontSize = Number(effective.font_size) && Number(effective.font_size) !== DEFAULT_FONT_SIZE
        ? fontSizePercent(effective.font_size)
        : '';
}
