/*
 * ADR-194 — le recadrage d'une photo d'identité 4 × 4 : un cadre carré, une
 * image qu'on déplace et qu'on agrandit. Ces calculs sont purs : l'écran s'en
 * sert pour afficher, le canevas pour produire le carré envoyé au serveur.
 *
 * `frame` est le côté du cadre à l'écran ; l'image couvre toujours le cadre
 * (jamais de bande vide) ; `zoom` vaut 1 quand le petit côté remplit le cadre.
 */

export const MIN_ZOOM = 1;
export const MAX_ZOOM = 3;

/** L'échelle qui fait tenir le petit côté de l'image dans le cadre. */
export function coverScale(width, height, frame) {
    return frame / Math.min(width, height);
}

/** Taille affichée de l'image pour ce zoom. */
export function displaySize(width, height, frame, zoom) {
    const scale = coverScale(width, height, frame) * zoom;
    return { width: width * scale, height: height * scale };
}

/** Garde l'image sur tout le cadre : ni bande vide à gauche, ni en bas. */
export function clampOffset(offset, width, height, frame, zoom) {
    const size = displaySize(width, height, frame, zoom);
    return {
        x: Math.min(0, Math.max(frame - size.width, offset.x)),
        y: Math.min(0, Math.max(frame - size.height, offset.y)),
    };
}

/**
 * La position de départ : centrée en largeur, et un peu haute pour une photo
 * en portrait — le visage est dans le tiers supérieur, pas au milieu.
 */
export function initialOffset(width, height, frame) {
    const size = displaySize(width, height, frame, 1);
    return clampOffset({ x: (frame - size.width) / 2, y: (frame - size.height) * 0.3 }, width, height, frame, 1);
}

/** Changer de zoom en gardant le point au centre du cadre au même endroit. */
export function zoomAround(offset, width, height, frame, fromZoom, toZoom) {
    const zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, toZoom));
    const ratio = zoom / fromZoom;
    const center = frame / 2;
    const next = {
        x: center - (center - offset.x) * ratio,
        y: center - (center - offset.y) * ratio,
    };

    return { zoom, offset: clampOffset(next, width, height, frame, zoom) };
}

/** Le carré à découper dans l'image d'origine, en pixels de l'image. */
export function sourceSquare(offset, width, height, frame, zoom) {
    const scale = coverScale(width, height, frame) * zoom;
    const side = Math.min(frame / scale, width, height);

    return {
        x: Math.max(0, Math.min(width - side, -offset.x / scale)),
        y: Math.max(0, Math.min(height - side, -offset.y / scale)),
        side,
    };
}
