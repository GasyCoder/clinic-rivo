/**
 * ADR-222 (amendement du 2026-09-29, bis) — la bulle de l'assistant : où elle se
 * pose, où s'ouvre sa fenêtre, et ce que le poste retient. Des fonctions pures,
 * testées par `node --test` ; le composant ne fait que les appeler.
 *
 * La bulle se déplace partout sur l'écran, comme une bulle de discussion : lâchée,
 * elle se colle au bord le plus proche (gauche ou droite) et garde sa hauteur.
 * La fenêtre s'ouvre à côté d'elle en trois tailles — petite, grande, plein écran —
 * et se déplace par son en-tête.
 */

/** Diamètre de la bulle, marge au bord de l'écran, écart entre bulle et fenêtre (px). */
export const BUBBLE_SIZE = 60;
export const EDGE_MARGIN = 16;
export const WINDOW_GAP = 12;
/** La barre du haut de l'application : la bulle ne s'y cache jamais. */
export const TOP_RESERVED = 72;
/** Un geste plus court que ce seuil est un clic, jamais un déplacement. */
export const DRAG_THRESHOLD = 5;
/** Sous cette largeur (téléphone), la fenêtre prend tout l'écran quelle que soit sa taille. */
export const PHONE_MAX_WIDTH = 640;

/** Les trois tailles de la fenêtre, et ce que chacune occupe au plus. */
export const WINDOW_MODES = Object.freeze({
    compact: { label: 'Petite fenêtre', width: 400, height: 620 },
    large: { label: 'Grande fenêtre', width: 780, height: 860 },
    full: { label: 'Plein écran', width: null, height: null },
});

export const DEFAULT_MODE = 'compact';
export const PREFERENCES_KEY = 'rivo:assistant:widget';

const clamp = (value, min, max) => Math.min(Math.max(value, min), Math.max(min, max));
const finite = (value, fallback) => (Number.isFinite(Number(value)) ? Number(value) : fallback);

/** @typedef {{ width: number, height: number }} Viewport */
/** @typedef {{ side: 'left'|'right', ratio: number }} BubbleAnchor  hauteur en part de l'écran (0 = en haut) */
/** @typedef {{ x: number, y: number }} Point */
/** @typedef {{ x: number, y: number, width: number, height: number }} Rect */

export const isPhone = (viewport) => finite(viewport?.width, 1024) < PHONE_MAX_WIDTH;

/** La taille réellement montrée : sur un téléphone, toujours le plein écran. */
export const effectiveMode = (mode, viewport) => {
    const known = Object.hasOwn(WINDOW_MODES, mode) ? mode : DEFAULT_MODE;

    return isPhone(viewport) ? 'full' : known;
};

/** La bulle à sa place par défaut : en bas à droite, au-dessus du pied de page. */
export const defaultBubbleAnchor = () => ({ side: 'right', ratio: 1 });

/** Une ancre relue du stockage ou d'un geste : jamais hors de l'écran, jamais un côté inconnu. */
export const normalizeAnchor = (anchor) => ({
    side: anchor?.side === 'left' ? 'left' : 'right',
    ratio: clamp(finite(anchor?.ratio, 1), 0, 1),
});

/** Les bornes verticales de la bulle : sous la barre du haut, au-dessus du bord bas. */
const bubbleYRange = (viewport) => {
    const height = finite(viewport?.height, 800);
    const min = Math.min(TOP_RESERVED, Math.max(EDGE_MARGIN, height - BUBBLE_SIZE - EDGE_MARGIN));

    return { min, max: height - BUBBLE_SIZE - EDGE_MARGIN };
};

/** Où poser la bulle pour une ancre donnée (coin haut-gauche, en px). */
export const bubblePosition = (anchor, viewport) => {
    const { side, ratio } = normalizeAnchor(anchor);
    const width = finite(viewport?.width, 1024);
    const { min, max } = bubbleYRange(viewport);

    return {
        x: side === 'left' ? EDGE_MARGIN : width - BUBBLE_SIZE - EDGE_MARGIN,
        y: Math.round(min + (Math.max(max - min, 0) * ratio)),
    };
};

/**
 * Pendant le glisser : la bulle suit le pointeur mais ne sort jamais de l'écran.
 * `point` est le coin haut-gauche voulu.
 */
export const clampBubble = (point, viewport) => {
    const width = finite(viewport?.width, 1024);
    const { min, max } = bubbleYRange(viewport);

    return {
        x: clamp(finite(point?.x, 0), EDGE_MARGIN, width - BUBBLE_SIZE - EDGE_MARGIN),
        y: clamp(finite(point?.y, 0), min, max),
    };
};

/** Lâchée : la bulle se colle au bord le plus proche, à la hauteur où on l'a posée. */
export const snapBubble = (point, viewport) => {
    const width = finite(viewport?.width, 1024);
    const { x, y } = clampBubble(point, viewport);
    const { min, max } = bubbleYRange(viewport);

    return {
        side: x + (BUBBLE_SIZE / 2) < width / 2 ? 'left' : 'right',
        ratio: max > min ? clamp((y - min) / (max - min), 0, 1) : 1,
    };
};

/** Au clavier : ↑ ↓ déplacent la bulle, ← → la changent de bord. */
export const nudgeBubble = (anchor, key, viewport, big = false) => {
    const current = normalizeAnchor(anchor);
    const { min, max } = bubbleYRange(viewport);
    const span = Math.max(max - min, 1);
    const step = (big ? 120 : 32) / span;

    switch (key) {
        case 'ArrowUp': return { ...current, ratio: clamp(current.ratio - step, 0, 1) };
        case 'ArrowDown': return { ...current, ratio: clamp(current.ratio + step, 0, 1) };
        case 'ArrowLeft': return { ...current, side: 'left' };
        case 'ArrowRight': return { ...current, side: 'right' };
        case 'Home': return { ...current, ratio: 0 };
        case 'End': return { ...current, ratio: 1 };
        default: return null;
    }
};

/** La taille de la fenêtre pour ce mode, bornée par l'écran. */
export const windowSize = (mode, viewport) => {
    const width = finite(viewport?.width, 1024);
    const height = finite(viewport?.height, 800);
    const shown = effectiveMode(mode, viewport);

    if (shown === 'full') return { width, height };

    const preset = WINDOW_MODES[shown];

    return {
        width: Math.min(preset.width, width - (2 * EDGE_MARGIN)),
        height: Math.min(preset.height, height - TOP_RESERVED - EDGE_MARGIN),
    };
};

/** Garde une fenêtre entière dans l'écran (sous la barre du haut). */
export const clampWindow = (point, size, viewport) => {
    const width = finite(viewport?.width, 1024);
    const height = finite(viewport?.height, 800);
    const top = Math.min(TOP_RESERVED, Math.max(EDGE_MARGIN, height - size.height - EDGE_MARGIN));

    return {
        x: clamp(finite(point?.x, 0), EDGE_MARGIN, width - size.width - EDGE_MARGIN),
        y: clamp(finite(point?.y, 0), top, height - size.height - EDGE_MARGIN),
    };
};

/**
 * Où ouvrir la fenêtre. Plein écran : tout l'écran. Sinon, à la place où on l'a
 * déplacée (`moved`, gardée dans l'écran), ou bien à côté de la bulle — du côté
 * du centre de l'écran, alignée sur elle ; s'il n'y a pas la place à côté, au-dessus.
 *
 * @returns {Rect & { mode: string, beside: boolean }}
 */
export const windowRect = ({ mode, anchor, moved = null, viewport }) => {
    const shown = effectiveMode(mode, viewport);
    const size = windowSize(shown, viewport);

    if (shown === 'full') return { mode: shown, beside: false, x: 0, y: 0, ...size };

    if (moved) return { mode: shown, beside: false, ...clampWindow(moved, size, viewport), ...size };

    const bubble = bubblePosition(anchor, viewport);
    const side = normalizeAnchor(anchor).side;
    const x = side === 'right'
        ? bubble.x - WINDOW_GAP - size.width
        : bubble.x + BUBBLE_SIZE + WINDOW_GAP;
    const width = finite(viewport?.width, 1024);
    const fitsBeside = x >= EDGE_MARGIN && x + size.width <= width - EDGE_MARGIN;

    if (fitsBeside) {
        return { mode: shown, beside: true, ...clampWindow({ x, y: bubble.y + BUBBLE_SIZE - size.height }, size, viewport), ...size };
    }

    // Pas la place à côté : la fenêtre s'aligne sur le bord de la bulle, au-dessus d'elle.
    const aboveX = side === 'right' ? bubble.x + BUBBLE_SIZE - size.width : bubble.x;

    return { mode: shown, beside: false, ...clampWindow({ x: aboveX, y: bubble.y - WINDOW_GAP - size.height }, size, viewport), ...size };
};

/** La fenêtre recouvre-t-elle la bulle ? Alors la bulle s'efface : la fenêtre a son bouton « Réduire ». */
export const coversBubble = (rect, anchor, viewport) => {
    if (! rect) return false;
    if (rect.mode === 'full') return true;

    const bubble = bubblePosition(anchor, viewport);

    return rect.x < bubble.x + BUBBLE_SIZE && rect.x + rect.width > bubble.x
        && rect.y < bubble.y + BUBBLE_SIZE && rect.y + rect.height > bubble.y;
};

/** Le mode suivant du bouton « Agrandir / Réduire la fenêtre » (hors plein écran). */
export const toggledSize = (mode) => (mode === 'large' ? 'compact' : 'large');

/* ------------------------------------------------------------------ */
/* Ce que le poste retient                                             */
/* ------------------------------------------------------------------ */

/**
 * Seulement l'emplacement de la bulle, la taille préférée et la place de la fenêtre :
 * une commodité du poste, comme la largeur du menu latéral. Jamais une question, une
 * réponse ou une conversation — celles-ci vivent sur le serveur, à chaque compte les
 * siennes. Un stockage refusé (navigation privée, données effacées) ne casse rien :
 * la bulle revient simplement à sa place par défaut.
 */
export const readPreferences = (storage) => {
    try {
        const raw = storage?.getItem(PREFERENCES_KEY);
        const json = raw ? JSON.parse(raw) : null;
        const moved = json?.moved && Number.isFinite(Number(json.moved.x)) && Number.isFinite(Number(json.moved.y))
            ? { x: Number(json.moved.x), y: Number(json.moved.y) }
            : null;

        return {
            anchor: normalizeAnchor(json?.anchor ?? defaultBubbleAnchor()),
            mode: Object.hasOwn(WINDOW_MODES, json?.mode) ? json.mode : DEFAULT_MODE,
            moved,
        };
    } catch {
        return { anchor: defaultBubbleAnchor(), mode: DEFAULT_MODE, moved: null };
    }
};

export const writePreferences = (storage, { anchor, mode, moved }) => {
    try {
        storage?.setItem(PREFERENCES_KEY, JSON.stringify({
            anchor: normalizeAnchor(anchor),
            mode: Object.hasOwn(WINDOW_MODES, mode) ? mode : DEFAULT_MODE,
            moved: moved ? { x: Math.round(moved.x), y: Math.round(moved.y) } : null,
        }));
    } catch {
        // Stockage refusé : la bulle garde sa place le temps de la visite.
    }
};
