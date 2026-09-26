import { Mail, TrendingUp } from 'lucide-vue-next';
import { CLINIC_WORKSPACES, ROLE_FOCUS, SIDEBAR_GROUPS, WORKSPACE_GROUPS } from './clinicWorkspaces.js';
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
            children: workspace.children,
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
 * Puts the members of one module under a single parent entry.
 *
 * The parent takes the place of its first member in the recommended order,
 * so a role whose primary workspace is Médecine still opens on Médecine.
 * Members keep their own permission and matching rules as children; the
 * group itself has neither — it is visible as long as one member is.
 */
export function groupRelatedItems(items) {
    const grouped = [];

    for (const item of items) {
        const family = SIDEBAR_GROUPS.find((candidate) => candidate.members.includes(item.key));

        if (!family) {
            grouped.push(item);
            continue;
        }

        if (grouped.some((row) => row.key === family.key)) continue;

        const members = family.members
            .map((key) => items.find((candidate) => candidate.key === key))
            .filter(Boolean);

        grouped.push({
            key: family.key,
            icon: family.icon,
            text: family.text,
            group: item.group,
            family: true,
            members,
            children: members.map((member) => ({
                code: member.key,
                icon: member.icon,
                label: family.labels?.[member.key] ?? member.text,
                link: member.link,
                activeLinks: member.activeLinks,
                exact: member.exact,
                permission: member.permission,
            })),
        });
    }

    return grouped;
}

/**
 * A stored order written before a module was grouped names its members;
 * each one now stands for its group, at the place the account gave it.
 */
function aliasStoredOrder(storedOrder) {
    if (!Array.isArray(storedOrder)) return storedOrder;

    return storedOrder.map((key) => SIDEBAR_GROUPS.find((family) => family.members.includes(key))?.key ?? key);
}

/**
 * Applies the account's stored order to one group.
 *
 * Every item is placed, always: the stored keys first, then anything the
 * order does not mention appended in its recommended position. A stored
 * order can therefore reorder the sidebar but never shorten it.
 */
export function orderGroup(items, storedOrder) {
    const keys = normalizeOrder(aliasStoredOrder(storedOrder), items.map((item) => item.key));
    const placed = keys.map((key) => items.find((item) => item.key === key)).filter(Boolean);

    return [...placed, ...items.filter((item) => !placed.includes(item))];
}

/**
 * @param {object} options
 * @param {string} options.roleCode      the account's role, for its recommended order
 * @param {(permission: string) => boolean} options.can
 * @param {Record<string, string[]>} options.stored  the account's stored order per group
 * @param {string} options.overviewLabel
 * @param {boolean} options.webmail  ADR-195 — the account holds an active professional
 *   address: its mailbox is listed next to the overview. Not a permission — only
 *   the titular of an address ever reads it, so no right could open it.
 */
export function buildClinicMenu({ roleCode, can, stored = {}, overviewLabel = 'Vue d’ensemble', webmail = false }) {
    const items = groupRelatedItems(recommendedItems(ROLE_FOCUS[roleCode] ?? null, can));

    return [
        { heading: 'Principal' },
        { icon: TrendingUp, text: overviewLabel, link: '/' },
        ...(webmail ? [{ key: 'webmail', icon: Mail, text: 'Messagerie', link: '/messagerie' }] : []),
        ...Object.entries(WORKSPACE_GROUPS).flatMap(([group, heading]) => [
            { heading, group },
            ...orderGroup(items.filter((item) => item.group === group), stored[group]),
        ]),
    ];
}

/**
 * Drops what this account may not open, and any heading left with nothing
 * under it. Visibility is decided here and nowhere else (ADR-007).
 *
 * A group (Pharmacie, Ressources humaines) keeps only the children this
 * account may open, and disappears when none is left. `anyPermission` opens
 * an entry shared by several screens (« Achats ») to an account allowed
 * into one of them.
 */
export function visibleMenu(rawMenu, can) {
    const visible = [];
    let pendingHeading = null;

    for (const rawItem of rawMenu) {
        if (rawItem.heading) {
            pendingHeading = rawItem;
            continue;
        }

        if (rawItem.permission && !can(rawItem.permission)) continue;

        let item = rawItem.children
            ? {
                ...rawItem,
                children: rawItem.children.filter((child) => (!child.permission || can(child.permission))
                    && (!child.anyPermission || child.anyPermission.some((permission) => can(permission)))),
            }
            : rawItem;

        if (item.children && !item.children.length) continue;

        // A module group reduced to one member is that member's plain link:
        // a dropdown holding a single entry is one click for nothing. The
        // row keeps the group's key, so a personal order still places it.
        if (item.family && item.children.length === 1) {
            const member = item.members.find((candidate) => candidate.key === item.children[0].code);
            item = { ...member, key: item.key, group: item.group };
        }

        if (pendingHeading) {
            visible.push(pendingHeading);
            pendingHeading = null;
        }

        visible.push(item);
    }

    return visible;
}
