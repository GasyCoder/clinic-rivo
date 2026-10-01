import { snapshotBones } from 'boneyard-js';
import {
    compactShape,
    pageShapeKey,
    readShapes,
    rememberShape,
    shapeFor,
    SNAPSHOT_CONFIG,
    writeShapes,
} from '@/utilities/pageShapes';

/**
 * Photographie chaque écran une fois rendu (ADR-185) : son squelette de
 * chargement, la fois suivante, aura ses vraies cartes et son vrai tableau.
 *
 * La photo est prise pendant un temps mort, jamais au clic : elle ne retarde
 * aucune navigation. Rien n'est lu ni écrit pendant le rendu (le serveur rend
 * aussi la page) : le stockage n'est ouvert qu'une fois l'application montée.
 */
export const PAGE_SHAPE_ROOT = '[data-page-shape-root]';

/** Le temps de laisser la page finir de s'installer (graphiques, listes chargées). */
export const CAPTURE_DELAY_MS = 900;

let store = null;
let timer = null;
let idle = null;

const storage = () => {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
};

const shapes = () => {
    if (store === null) store = typeof window === 'undefined' ? {} : readShapes(storage());

    return store;
};

const cancel = () => {
    clearTimeout(timer);
    timer = null;

    if (idle !== null && typeof window.cancelIdleCallback === 'function') window.cancelIdleCallback(idle);
    idle = null;
};

const whenIdle = (callback) => (typeof window.requestIdleCallback === 'function'
    ? window.requestIdleCallback(callback, { timeout: 1500 })
    : setTimeout(callback, 0));

/** Photographie l'écran affiché, s'il est visible (jamais pendant son propre squelette). */
export function capturePageShape() {
    const root = document.querySelector(PAGE_SHAPE_ROOT);
    if (! root) return null;

    const rect = root.getBoundingClientRect();
    if (rect.width < 1 || rect.height < 1) return null;

    const shape = compactShape(snapshotBones(root, 'page', SNAPSHOT_CONFIG));
    if (! shape) return null;

    store = rememberShape(shapes(), pageShapeKey(window.location.pathname), shape);
    writeShapes(storage(), store);

    return shape;
}

export function installPageShapes(router, delay = CAPTURE_DELAY_MS) {
    if (typeof window === 'undefined') return;

    const schedule = () => {
        cancel();
        timer = setTimeout(() => {
            timer = null;
            idle = whenIdle(() => {
                idle = null;
                capturePageShape();
            });
        }, delay);
    };

    // Toute page affichée, y compris la première et celles qu'un filtre ou un onglet
    // vient de modifier ; un départ vers une autre page annule la photo en attente.
    router.on('navigate', schedule);
    router.on('start', cancel);
}

/**
 * La forme de l'écran visé à cette largeur, photographiée sur ce poste ; sinon
 * aucune — la mise en page dessine alors la forme générique de `PageSkeleton`.
 */
export function pageShapeFor(path, width) {
    return shapeFor(shapes(), pageShapeKey(path), width);
}
