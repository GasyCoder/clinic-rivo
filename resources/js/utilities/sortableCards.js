/**
 * Repair a remembered card order against the identifiers currently visible.
 * Old/deleted identifiers disappear, duplicates are ignored and new cards are
 * appended in the server order so a stale preference can never hide a card.
 */
export function normalizeCardOrder(candidate, identifiers) {
    const known = new Set(identifiers);
    const kept = Array.isArray(candidate)
        ? candidate.filter((identifier) => typeof identifier === 'string' && known.has(identifier))
        : [];
    const unique = [...new Set(kept)];

    return [...unique, ...identifiers.filter((identifier) => !unique.includes(identifier))];
}

/** Move one card to a precise position without mutating the source array. */
export function moveCard(order, identifier, targetIndex) {
    const from = order.indexOf(identifier);

    if (from === -1 || targetIndex < 0 || targetIndex >= order.length || from === targetIndex) return order.slice();

    const next = order.slice();
    next.splice(targetIndex, 0, ...next.splice(from, 1));

    return next;
}

/**
 * Save a reordered filtered result without losing identifiers hidden by the
 * current search/status filter. Visible cards replace their existing slots;
 * newly discovered cards are appended.
 */
export function mergeVisibleCardOrder(storedOrder, visibleOrder) {
    const visible = [...new Set(visibleOrder)];
    const visibleSet = new Set(visible);
    const stored = Array.isArray(storedOrder) ? [...new Set(storedOrder)] : [];

    if (!stored.length) return visible;

    let cursor = 0;
    const merged = stored.map((identifier) => (
        visibleSet.has(identifier) ? visible[cursor++] : identifier
    ));

    return [...merged, ...visible.slice(cursor)];
}
