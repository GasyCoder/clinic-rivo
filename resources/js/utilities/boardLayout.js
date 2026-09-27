/**
 * La disposition de cartes rangées en colonnes, que chacun arrange à sa main
 * (accueil RH). Une disposition est `{ colonne: [identifiants] }`.
 *
 * Fonctions pures, sans navigateur : le glisser-déposer, les flèches et la
 * réinitialisation passent tous par elles, et elles se testent seules.
 */

/**
 * Répare une disposition enregistrée au lieu de la croire.
 *
 * Elle survit à l'écran qui l'a produite : une carte peut apparaître ou
 * disparaître d'une version à l'autre. Une carte connue garde la place choisie,
 * une inconnue est oubliée, un doublon n'est gardé qu'une fois, et une carte
 * nouvelle rejoint sa colonne d'origine — aucune ne devient jamais introuvable.
 */
export const normalizeLayout = (candidate, defaults) => {
    const known = new Set(Object.values(defaults).flat());
    const seen = new Set();
    const layout = {};

    for (const column of Object.keys(defaults)) {
        const stored = candidate && Array.isArray(candidate[column]) ? candidate[column] : [];

        layout[column] = stored.filter((id) => {
            if (typeof id !== 'string' || ! known.has(id) || seen.has(id)) return false;
            seen.add(id);

            return true;
        });
    }

    for (const [column, ids] of Object.entries(defaults)) {
        for (const id of ids) {
            if (! seen.has(id)) {
                layout[column].push(id);
                seen.add(id);
            }
        }
    }

    return layout;
};

export const copyLayout = (layout) => Object.fromEntries(Object.entries(layout).map(([column, ids]) => [column, [...ids]]));

/** Où se trouve une carte : sa colonne et son rang, ou `null`. */
export const locate = (layout, id) => {
    for (const [column, ids] of Object.entries(layout)) {
        const index = ids.indexOf(id);

        if (index !== -1) return { column, index };
    }

    return null;
};

/**
 * Place une carte dans une colonne, au rang voulu (borné). Renvoie une
 * nouvelle disposition ; l'ancienne n'est jamais modifiée.
 */
export const moveCard = (layout, id, column, index) => {
    const from = locate(layout, id);

    if (! from || ! Array.isArray(layout[column])) return layout;

    const next = copyLayout(layout);
    next[from.column].splice(from.index, 1);

    const target = Math.max(0, Math.min(index, next[column].length));
    next[column].splice(target, 0, id);

    return next;
};

/** Une colonne voisine, dans l'ordre des colonnes ; `null` au bord. */
export const neighbourColumn = (layout, column, delta) => {
    const columns = Object.keys(layout);
    const index = columns.indexOf(column) + delta;

    return index >= 0 && index < columns.length ? columns[index] : null;
};

export const sameLayout = (left, right) => JSON.stringify(left) === JSON.stringify(right);
