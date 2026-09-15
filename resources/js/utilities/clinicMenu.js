import { TrendingUp } from 'lucide-vue-next';
import { CLINIC_WORKSPACES, ROLE_FOCUS, WORKSPACE_GROUPS } from './clinicWorkspaces.js';
import { normalizeOrder } from '../composables/useSidebarOrder.js';

/**
 * Builds the clinic sidebar: the workspaces, in the order this account
 * should see them, grouped under their headings.
 *
 * Pure on purpose — it takes `can` and the stored order rather than
 * reaching for them — so the one invariant that matters can actually be
 * tested: whatever a role recommends and whatever an account has dragged
 * around, every permitted workspace appears exactly once.
 */

/** The workspace a role's primary action belongs to, longest link wins. */
function primaryWorkspaceOf(roleFocus) {
    if (!roleFocus?.primary?.link) return null;

    return [...CLINIC_WORKSPACES]
        .filter((workspace) => roleFocus.primary.link === workspace.link
            || roleFocus.primary.link.startsWith(`${workspace.link}/`))
        .sort((left, right) => right.link.length - left.link.length)[0] ?? null;
}

/**
 * The role's recommended order: its primary workspace first, then its
 * shortcuts, then the catalogue's own order. Only ever a default — an
 * account's stored order is applied on top of it.
 */
export function recommendedItems(roleFocus, can) {
    const focusOrder = [primaryWorkspaceOf(roleFocus)?.key, ...(roleFocus?.shortcuts ?? [])].filter(Boolean);

    return CLINIC_WORKSPACES
        .map((workspace, originalIndex) => ({
            key: workspace.key,
            icon: workspace.icon,
            text: workspace.text,
            link: workspace.resolveLink ? workspace.resolveLink(can) : workspace.link,
            activeLinks: workspace.activeLinks,
            exact: workspace.exact,
            permission: workspace.permission,
            group: workspace.group,
            originalIndex,
        }))
        .sort((left, right) => {
            if (left.group !== right.group) return left.originalIndex - right.originalIndex;

            const leftFocus = focusOrder.indexOf(left.key);
            const rightFocus = focusOrder.indexOf(right.key);

            if (leftFocus !== -1 || rightFocus !== -1) {
                if (leftFocus === -1) return 1;
                if (rightFocus === -1) return -1;

                return leftFocus - rightFocus;
            }

            return left.originalIndex - right.originalIndex;
        });
}

/**
 * Applies the account's stored order to one group.
 *
 * Every item is placed, always: the stored keys first, then anything the
 * order does not mention appended in its recommended position. A stored
 * order can therefore reorder the sidebar but never shorten it.
 */
export function orderGroup(items, storedOrder) {
    const keys = normalizeOrder(storedOrder, items.map((item) => item.key));
    const placed = keys.map((key) => items.find((item) => item.key === key)).filter(Boolean);

    return [...placed, ...items.filter((item) => !placed.includes(item))];
}

/**
 * @param {object} options
 * @param {string} options.roleCode      the account's role, for its recommended order
 * @param {(permission: string) => boolean} options.can
 * @param {Record<string, string[]>} options.stored  the account's stored order per group
 * @param {string} options.overviewLabel
 */
export function buildClinicMenu({ roleCode, can, stored = {}, overviewLabel = 'Vue d’ensemble' }) {
    const items = recommendedItems(ROLE_FOCUS[roleCode] ?? null, can);

    return [
        { heading: 'Principal' },
        { icon: TrendingUp, text: overviewLabel, link: '/' },
        ...Object.entries(WORKSPACE_GROUPS).flatMap(([group, heading]) => [
            { heading, group },
            ...orderGroup(items.filter((item) => item.group === group), stored[group]),
        ]),
    ];
}

/**
 * Drops what this account may not open, and any heading left with nothing
 * under it. Visibility is decided here and nowhere else (ADR-007).
 */
export function visibleMenu(rawMenu, can) {
    const visible = [];
    let pendingHeading = null;

    for (const item of rawMenu) {
        if (item.heading) {
            pendingHeading = item;
            continue;
        }

        if (item.permission && !can(item.permission)) continue;

        if (pendingHeading) {
            visible.push(pendingHeading);
            pendingHeading = null;
        }

        visible.push(item);
    }

    return visible;
}
