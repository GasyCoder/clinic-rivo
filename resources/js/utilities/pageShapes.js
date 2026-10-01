/**
 * La forme d'une page, pour son squelette de chargement (ADR-185).
 *
 * Boneyard photographie la page réellement affichée (`snapshotBones`) : chaque
 * bloc de texte, chaque bouton, chaque carte devient un rectangle. La photo est
 * rangée sous le motif de l'adresse — `/patients/9f3c…` et `/patients/42ab…`
 * partagent `/patients/:id` — pour que la visite suivante de cet écran montre
 * ses vraies cartes, ses compteurs et son tableau.
 *
 * Une forme ne contient que des rectangles : ni texte, ni donnée. Elle est
 * gardée sur le poste (localStorage), bornée en nombre et en hauteur.
 */

export const SHAPES_STORAGE_KEY = 'rivo:page-shapes:v1';

/** Écrans gardés au plus : les moins récemment vus partent les premiers. */
export const MAX_STORED_SHAPES = 60;

/** Largeurs gardées par écran (téléphone, tablette, ordinateur…). */
export const MAX_WIDTHS_PER_SHAPE = 3;

/** Au-delà, la page n'a pas la même disposition : la forme n'est pas reprise. */
export const WIDTH_TOLERANCE = 0.12;

/** Hauteur dessinée au plus : un tableau de mille lignes n'a pas à l'être. */
export const MAX_SHAPE_HEIGHT = 2400;

/** Un rectangle plus petit n'apporte rien (texte réservé aux lecteurs d'écran). */
const MIN_BONE_PX = 4;

/** Ce que Boneyard ne doit pas photographier. */
export const SNAPSHOT_CONFIG = {
    excludeSelectors: ['[data-skeleton-ignore]', '.sr-only', '[role="status"]'],
};

/**
 * Le motif d'une adresse : tout segment qui porte un chiffre (UUID, numéro,
 * numéro de passage) devient `:id`. La requête et le fragment sont ignorés.
 */
export function pageShapeKey(url) {
    const path = String(url ?? '').split(/[?#]/)[0] || '/';
    const segments = path.split('/').filter(Boolean).map((segment) => (/\d/.test(segment) ? ':id' : segment));

    return `/${segments.join('/')}`;
}

const pixelWidth = (bone, width) => (bone[2] / 100) * width;

/**
 * Réduit une photo Boneyard à ce qui se dessine : rectangles visibles, sous la
 * hauteur maximale, au format compact `[x %, y px, w %, h px, rayon, carte?]`.
 */
export function compactShape(snapshot, now = Date.now()) {
    if (! snapshot || ! Array.isArray(snapshot.bones) || ! (snapshot.width > 0)) return null;

    const width = Math.round(snapshot.width);
    const bones = [];

    for (const raw of snapshot.bones) {
        const bone = Array.isArray(raw)
            ? raw
            : [raw.x, raw.y, raw.w, raw.h, raw.r, raw.c === true];

        const [, y, , h] = bone;

        if (y >= MAX_SHAPE_HEIGHT) continue;
        if (h < MIN_BONE_PX || pixelWidth(bone, width) < MIN_BONE_PX) continue;

        bones.push(bone[5] ? [bone[0], y, bone[2], h, bone[4], true] : [bone[0], y, bone[2], h, bone[4]]);
    }

    // Une page vide ou encore en train de se monter ne fait pas une forme.
    if (bones.filter((bone) => ! bone[5]).length < 3) return null;

    return {
        width,
        height: Math.min(Math.round(snapshot.height), MAX_SHAPE_HEIGHT),
        bones,
        at: now,
    };
}

/** Range une forme sous son motif, en gardant au plus quelques largeurs. */
export function rememberShape(store, key, shape) {
    if (! shape) return store;

    const next = { ...store };
    const widths = (next[key] ?? []).filter((known) => ! sameWidth(known.width, shape.width));

    next[key] = [shape, ...widths].slice(0, MAX_WIDTHS_PER_SHAPE);

    const keys = Object.keys(next);
    if (keys.length > MAX_STORED_SHAPES) {
        const newest = (entry) => Math.max(...entry.map((item) => item.at ?? 0));
        keys.sort((a, b) => newest(next[b]) - newest(next[a]))
            .slice(MAX_STORED_SHAPES)
            .forEach((old) => delete next[old]);
    }

    return next;
}

const sameWidth = (a, b) => a > 0 && b > 0 && Math.abs(a - b) / Math.max(a, b) <= WIDTH_TOLERANCE;

/** La forme d'un écran à cette largeur : la plus proche, et seulement si elle est comparable. */
export function shapeFor(store, key, width) {
    const candidates = (store?.[key] ?? []).filter((shape) => sameWidth(shape.width, width));

    if (! candidates.length) return null;

    return candidates.sort((a, b) => Math.abs(a.width - width) - Math.abs(b.width - width))[0];
}

/** Ce qui est gardé sur le poste — jamais une erreur si le stockage est refusé ou abîmé. */
export function readShapes(storage) {
    try {
        const parsed = JSON.parse(storage?.getItem(SHAPES_STORAGE_KEY) ?? '{}');

        return parsed && typeof parsed === 'object' && ! Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

export function writeShapes(storage, store) {
    try {
        storage?.setItem(SHAPES_STORAGE_KEY, JSON.stringify(store));
    } catch {
        // Stockage plein ou refusé (navigation privée) : la forme reste en mémoire.
    }
}
