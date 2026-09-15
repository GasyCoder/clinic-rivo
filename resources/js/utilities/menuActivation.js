/**
 * Which sidebar entry the current URL belongs to.
 *
 * Several workspaces legitimately share a prefix — `/reception`,
 * `/reception/sorties`, `/reception/visitors`. Matching on a bare
 * `startsWith` lit all of them at once, so the sidebar showed two or three
 * "current" pages. Specificity decides instead: the deepest match wins.
 */

/**
 * Segment-aware containment: `/reception` covers `/reception/patients` but
 * never `/reception-visiteurs`, which a bare prefix test also swallows.
 */
export function pathCovers(path, link) {
    return path === link || path.startsWith(`${link}/`);
}

/**
 * How specifically one entry claims `path` — the length of the link it
 * matched on, or -1 when it does not match at all. Length is the ranking:
 * a longer matching link is necessarily a deeper one.
 */
export function menuMatchDepth(item, path) {
    if (item.activeLinks) {
        // A group (Ressources humaines) claims its sub-pages through
        // activeLinks and, when `exact`, its own home page as well.
        const own = item.exact && item.link && path === item.link ? item.link.length : -1;

        return item.activeLinks.reduce(
            (best, link) => (pathCovers(path, link) ? Math.max(best, link.length) : best),
            own,
        );
    }

    if (!item.link) {
        return -1;
    }

    // "/" would otherwise cover every page, and an `exact` entry is meant
    // to claim its own URL only.
    if (item.exact || item.link === '/') {
        return path === item.link ? item.link.length : -1;
    }

    return pathCovers(path, item.link) ? item.link.length : -1;
}

/**
 * The entries to highlight. Only the visible ones compete, so when the
 * deepest match is hidden by a permission its parent workspace stays lit
 * rather than nothing at all.
 */
export function activeMenuKeys(items, path) {
    const depths = items.map((item) => menuMatchDepth(item, path));
    const deepest = depths.reduce((best, depth) => Math.max(best, depth), -1);

    return depths.map((depth, index) => (depth !== -1 && depth === deepest ? index : -1))
        .filter((index) => index !== -1);
}
