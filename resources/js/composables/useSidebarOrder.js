import { computed, getCurrentInstance, onMounted, ref, watch } from 'vue';

/**
 * Lets each account choose the vertical order of its sidebar workspaces.
 *
 * Same interaction contract as SortableSections (the repo's existing
 * reorder control): nothing moves until customising is explicitly turned
 * on, the drag handle is the mouse affordance, and the up/down arrows are
 * the primary control rather than a fallback — they work with a finger,
 * a mouse and a keyboard alike, where HTML5 drag simply does not exist on
 * touch.
 *
 * Purely presentational: the order changes nothing about which modules an
 * account may open. Visibility is decided by the dynamic permission on
 * each workspace, before this ever runs (ADR-007).
 *
 * Reordering stays *inside* a heading group. Dragging "Corbeille" up into
 * "Gestion clinique" would make the headings state something false, and
 * the headings are what tell a nurse which half of the menu is clinical.
 *
 * Persistence is `localStorage`, keyed by the account id. A clinic
 * workstation is shared (ADR-073), so an unkeyed preference would hand the
 * next person the previous one's menu; keying it by account keeps each
 * login's own. Unlike a care draft, this holds no clinical or personal
 * data — it is a cosmetic preference, never read by any module, and its
 * loss costs a few seconds of re-ordering. It does not follow an account
 * to another machine; that would need a server-side preference.
 */
const STORAGE_PREFIX = 'rivo.sidebar-order';

/**
 * Repairs a stored order instead of trusting it.
 *
 * A preference outlives the build that produced it: modules get added,
 * removed or renamed, and permissions change what an account can even see.
 * Known keys keep the user's order, unknown ones are dropped, and anything
 * new is appended where the recommended order puts it — so an old
 * preference can never hide a module or leave a gap.
 */
export function normalizeOrder(candidate, recommended) {
    const known = new Set(recommended);
    const kept = Array.isArray(candidate)
        ? [...new Set(candidate.filter((key) => typeof key === 'string' && known.has(key)))]
        : [];

    return [...kept, ...recommended.filter((key) => !kept.includes(key))];
}

export function useSidebarOrder(userId) {
    const stored = ref({});
    const customizing = ref(false);
    const draggingKey = ref(null);
    const movedKey = ref(null);

    const storageKey = computed(() => (userId.value ? `${STORAGE_PREFIX}.${userId.value}` : null));

    // Read only once the browser has taken over — never while rendering.
    //
    // The app IS server-rendered (Inertia SSR). The server cannot see
    // `localStorage`, so it renders the recommended order; reading the
    // stored order during setup made the first client render differ from
    // the HTML it hydrates. Vue does not repair attribute and text
    // mismatches in production: a row kept one module's label while
    // pointing to another's link — « Soins » opening Patients, icons shifted
    // by one row, the highlight on the wrong entry. Constaté le 2026-09-18.
    //
    // The layout is persistent, so this runs once per full page load, not
    // on every Inertia navigation.
    const load = () => {
        stored.value = {};

        if (!storageKey.value) return;

        try {
            const raw = JSON.parse(window.localStorage.getItem(storageKey.value));
            if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
                stored.value = raw;
            }
        } catch {
            // Private window, blocked site data, corrupted value: the
            // recommended order is always a correct sidebar.
        }
    };

    if (getCurrentInstance()) {
        onMounted(load);
    } else {
        load();
    }
    watch(storageKey, load);

    const persist = () => {
        if (!storageKey.value) return;

        try {
            window.localStorage.setItem(storageKey.value, JSON.stringify(stored.value));
        } catch {
            // Storage unavailable: the order still holds for this visit.
        }
    };

    /** The stored order for one group, repaired against what is visible. */
    const orderFor = (group, recommended) => normalizeOrder(stored.value[group], recommended);

    /**
     * Whether this account has stored an order at all.
     *
     * Deliberately not "does the displayed order differ from the
     * recommended one": the displayed order *is* the stored one, so that
     * comparison can only ever answer no.
     */
    const hasStoredOrder = computed(() => Object.keys(stored.value).length > 0);

    /** Briefly marks the row that just moved, so the eye can follow it. */
    const flash = (key) => {
        movedKey.value = key;
        window.setTimeout(() => {
            if (movedKey.value === key) movedKey.value = null;
        }, 900);
    };

    const placeAt = (group, recommended, key, index) => {
        const current = orderFor(group, recommended);
        const from = current.indexOf(key);

        if (from === -1 || index < 0 || index >= current.length || index === from) return false;

        const next = current.slice();
        next.splice(index, 0, ...next.splice(from, 1));
        stored.value = { ...stored.value, [group]: next };

        return true;
    };

    const move = (group, recommended, key, delta) => {
        if (!placeAt(group, recommended, key, orderFor(group, recommended).indexOf(key) + delta)) return;

        persist();
        flash(key);
    };

    const resetAll = () => {
        stored.value = {};

        if (!storageKey.value) return;

        try {
            window.localStorage.removeItem(storageKey.value);
        } catch {
            // Nothing to clean up if storage is unavailable.
        }
    };

    /* ---------------------------------------------------------------- */
    /* Mouse drag. Touch has no HTML5 drag — it uses the arrows.         */
    /* ---------------------------------------------------------------- */

    const onDragStart = (event, key) => {
        draggingKey.value = key;
        event.dataTransfer.effectAllowed = 'move';
        // Firefox refuses to start a drag with nothing on the transfer.
        event.dataTransfer.setData('text/plain', key);
    };

    /**
     * Live reorder: the list rearranges under the pointer, so the pointer
     * *is* the placeholder — what you see mid-drag is already the result.
     */
    const onDragOver = (event, group, recommended, key) => {
        if (draggingKey.value === null || draggingKey.value === key) return;

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        placeAt(group, recommended, draggingKey.value, orderFor(group, recommended).indexOf(key));
    };

    const onDragEnd = () => {
        if (draggingKey.value === null) return;

        flash(draggingKey.value);
        draggingKey.value = null;
        persist();
    };

    return {
        customizing,
        draggingKey,
        movedKey,
        // The raw preference. The menu builder applies it, so ordering
        // lives in one place instead of half here and half there.
        storedOrder: stored,
        hasStoredOrder,
        move,
        resetAll,
        onDragStart,
        onDragOver,
        onDragEnd,
    };
}
