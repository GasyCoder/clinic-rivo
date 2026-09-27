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
    return zoomAtPoint(offset, width, height, frame, fromZoom, toZoom, { x: frame / 2, y: frame / 2 });
}

/**
 * Changer de zoom en gardant sous le pointeur (molette, pincement) le même
 * point de l'image : on zoome là où l'on regarde, pas au milieu du cadre.
 */
export function zoomAtPoint(offset, width, height, frame, fromZoom, toZoom, point) {
    const zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, toZoom));
    const ratio = zoom / fromZoom;
    const next = {
        x: point.x - (point.x - offset.x) * ratio,
        y: point.y - (point.y - offset.y) * ratio,
    };

    return { zoom, offset: clampOffset(next, width, height, frame, zoom) };
}

/** Les quarts de tour permis : 0, 90, 180, 270 degrés. */
export function normalizeRotation(degrees) {
    return ((Math.round(degrees / 90) * 90) % 360 + 360) % 360;
}

/** Les dimensions de l'image une fois tournée : un quart de tour échange largeur et hauteur. */
export function rotatedSize(width, height, rotation) {
    return normalizeRotation(rotation) % 180 === 0 ? { width, height } : { width: height, height: width };
}

/**
 * Ce que vaudra la photo envoyée : 600 px de côté au plus, jamais agrandie.
 * Sous 360 px, elle sera floue à l'impression de la fiche (cadre 4 × 4 cm).
 */
export function cropQuality(side, output = 600) {
    const pixels = Math.min(output, Math.round(side));
    const level = pixels >= output ? 'excellent' : pixels >= 360 ? 'good' : 'low';

    return { pixels, level };
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
