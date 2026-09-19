<script setup>
import { computed, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowUpDown,
    Briefcase,
    Building2,
    Check,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    ClipboardList,
    FileText,
    GripVertical,
    History,
    MapPin,
    Package,
    Pill,
    Settings,
    ShieldCheck,
    Trash2,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { useSidebarOrder } from '@/composables/useSidebarOrder';
import { buildClinicMenu, visibleMenu } from '@/utilities/clinicMenu';
import { menuMatchDepth } from '@/utilities/menuActivation';

const visibility = defineModel('visibility');
// Reordering needs the labels the collapsed rail hides, and a rail that
// re-collapses when the pointer leaves would drop a drag mid-way.
const props = defineProps({ compact: Boolean });

const page = usePage();
const { can } = usePermissions();
const isAdminPortal = computed(() => page.props.site?.type === 'admin');
const currentUser = computed(() => page.props.auth?.user ?? null);
const roleLabel = computed(() => currentUser.value?.role?.name ?? 'Accès clinique');
const profileLabel = computed(() => currentUser.value?.professional_profile?.name ?? null);
const overviewLabel = computed(() => (
    page.props.auth?.user?.role?.code === 'SUPER_ADMIN' ? 'Dashboard' : 'Vue d’ensemble'
));
const {
    customizing,
    draggingKey,
    movedKey,
    storedOrder,
    hasStoredOrder,
    move,
    resetAll,
    onDragStart,
    onDragOver,
    onDragEnd,
} = useSidebarOrder(computed(() => currentUser.value?.id ?? null));

// Customising is only offered where it can be done safely: the clinic
// sidebar, expanded. The admin portal's sites are collapsible trees with
// their own interaction, and are deliberately left alone.
const canCustomize = computed(() => !isAdminPortal.value && !props.compact);

watch(canCustomize, (allowed) => {
    if (!allowed) customizing.value = false;
});

/** The reorderable siblings of one row, in their displayed order. */
const siblings = (group) => (group
    ? menuData.value.filter((item) => !item.heading && item.group === group)
    : []);

const positionOf = (item, group) => siblings(group).findIndex((row) => row.key === item.key);

const moveRow = (item, group, delta) => move(
    group,
    siblings(group).map((row) => row.key),
    item.key,
    delta,
);

const dragOverRow = (event, item, group) => onDragOver(
    event,
    group,
    siblings(group).map((row) => row.key),
    item.key,
);

// Built from the shared workspace list, so the sidebar and the overview can
// never disagree on which modules exist or which permission opens them.
// The account's own order is applied on top of the role's recommended one;
// an account that never customised anything sees exactly what it saw before.
const clinicMenu = computed(() => buildClinicMenu({
    roleCode: currentUser.value?.role?.code,
    can,
    stored: storedOrder.value,
    overviewLabel: overviewLabel.value,
}));

const adminMenu = computed(() => [
    { heading: 'Vue centrale' },
    { icon: TrendingUp, text: overviewLabel.value, link: '/' },
    { heading: 'Établissements' },
    ...(page.props.adminNavigation ?? []).map((site) => ({
        icon: Building2,
        text: site.name,
        permission: 'sites.view',
        activeLinks: [`/super-admin/sites/${site.code}`],
        children: site.modules,
        integrationStatus: site.integration_status,
    })),
    { heading: 'Finance & caisse' },
    { icon: Wallet, text: 'Caisses des sites', link: '/super-admin/cash-registers', permission: 'cash_registers.view' },
    { icon: ClipboardList, text: 'Modes de paiement', link: '/super-admin/payment-methods', permission: 'payment_methods.view' },
    { icon: Wallet, text: 'Rapports financiers', link: '/super-admin/workspaces/finance', permission: 'reports.financial.view' },
    { heading: 'Référentiels & stocks' },
    { icon: FileText, text: 'Tarifs & mutuelles', link: '/super-admin/workspaces/tariffs', permission: 'catalog.items.view' },
    { icon: FileText, text: 'Canevas de documents', link: '/super-admin/workspaces/document-templates', permission: 'document_templates.view' },
    { icon: Activity, text: 'Catalogue des analyses', link: '/super-admin/analyses', permission: 'analysis_catalog.view' },
    { icon: Pill, text: 'Stock médicaments', link: '/super-admin/stock', permission: 'stock.view' },
    { icon: Building2, text: 'Fournisseurs pharmacie', link: '/super-admin/pharmacy-suppliers', permission: 'medicine_suppliers.view' },
    { icon: MapPin, text: 'Adresses & localités', link: '/super-admin/addresses', permission: 'address_entries.view' },
    { heading: 'Organisation' },
    { icon: Briefcase, text: 'Ressources humaines', link: '/super-admin/workspaces/hr', permission: 'employees.view' },
    { icon: Package, text: 'Logistique & équipements', link: '/super-admin/workspaces/logistics', permission: 'logistics.view' },
    { icon: ShieldCheck, text: 'Gardiennage', link: '/super-admin/workspaces/guarding', permission: 'guarding.view' },
    { heading: 'Sécurité & système' },
    { icon: Users, text: 'Utilisateurs', link: '/super-admin/workspaces/users', permission: 'users.view' },
    { icon: ShieldCheck, text: 'Rôles & permissions', link: '/super-admin/workspaces/roles', permission: 'roles.view' },
    { icon: Trash2, text: 'Corbeille', link: '/super-admin/trash', permission: 'trash.view' },
    { icon: Settings, text: 'Paramètres', link: '/super-admin/workspaces/settings', permission: 'settings.view' },
    { icon: History, text: 'Audit & APIs', link: '/super-admin/workspaces/audit', permission: 'audit.view' },
]);

const rawMenu = computed(() => page.props.site?.type === 'admin' ? adminMenu.value : clinicMenu.value);

// A heading is only rendered when at least one item under it is visible.
// Every operational item is gated by a dynamic permission; the only item
// intentionally shared by all active accounts is the overview.
const menuData = computed(() => visibleMenu(rawMenu.value, can));

/**
 * A stable identity per row. Workspaces carry their own key; the overview
 * and the admin portal's entries are identified by what does not move —
 * their link, or their label for the collapsible site trees.
 */
const rowKey = (item) => (item.heading
    ? `heading:${item.heading}`
    : `row:${item.key ?? item.link ?? item.text}`);

const visibleWorkspaceCount = computed(() => menuData.value.filter((item) => !item.heading && item.link !== '/').length);

const currentPath = computed(() => page.url.split('?')[0]);

// Only the visible items compete: when the deepest match is hidden by a
// permission, its parent workspace stays highlighted rather than nothing.
const deepestMatch = computed(() => menuData.value.reduce(
    (best, item) => (item.heading ? best : Math.max(best, menuMatchDepth(item, currentPath.value))),
    -1,
));

const isActive = (item) => {
    const depth = menuMatchDepth(item, currentPath.value);

    return depth !== -1 && depth === deepestMatch.value;
};

/**
 * One child lit at a time, and only inside the entry that owns the page.
 *
 * `startsWith` on each child independently lit « File de consultation »
 * (/medicine) together with « Demandes d’examens » (/medicine/demandes-
 * examens). Children compete like top-level entries: the deepest match
 * wins, with the same segment-aware rules (menuActivation.js).
 */
const isChildActive = (item, child) => {
    // The portal's site modules are addressed by query (`?module=CASH`) on
    // one path: they match the full URL, as they always have.
    if (isAdminPortal.value) {
        return page.url === child.link
            || (child.code === 'OVERVIEW' && currentPath.value === child.link.split('?')[0]);
    }

    if (!isActive(item)) return false;

    const depth = menuMatchDepth(child, currentPath.value);

    return depth !== -1
        && depth === Math.max(...item.children.map((sibling) => menuMatchDepth(sibling, currentPath.value)));
};

const closeMobile = () => {
    visibility.value = false;
};
</script>

<template>
    <ul class="nk-menu px-3 pb-5">
        <li v-if="!isAdminPortal" class="group-[&.is-compact:not(.has-hover)]/sidebar:hidden px-1 pb-1 pt-3">
            <div class="relative overflow-hidden rounded-lg border border-border bg-muted/40 px-3 py-3 shadow-sm dark:border-white/5 dark:bg-white/[0.035]">
                <span class="absolute inset-y-0 start-0 w-0.5 bg-primary" />
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary ring-1 ring-inset ring-primary/20">
                        <ShieldCheck class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-bold text-foreground">{{ profileLabel || roleLabel }}</p>
                        <p class="mt-0.5 truncate text-[10px] text-muted-foreground">{{ profileLabel ? roleLabel : 'Espace professionnel' }} · {{ visibleWorkspaceCount }} module{{ visibleWorkspaceCount > 1 ? 's' : '' }}</p>
                    </div>
                </div>
            </div>
        </li>

        <!-- Keyed by identity, never by index: the list is reorderable, so
             Vue must MOVE each row's nodes instead of repatching whatever
             sits at that position. With an index key a row keeps the DOM
             node of the row that was there before — the node being dragged
             gets its content swapped mid-drag, and rows end up wearing each
             other's icons (same rule as SortableSections). -->
        <template v-for="item in menuData" :key="rowKey(item)">
            <li
                v-if="item.heading"
                :class="[
                    'relative first:pt-1 pb-2 before:absolute before:h-px before:w-full before:start-0 before:top-1/2 before:bg-border first:before:hidden before:opacity-0 group-[&.is-compact:not(.has-hover)]/sidebar:before:opacity-100',
                    isAdminPortal ? 'px-3 pt-6' : 'px-2 pt-6',
                ]"
            >
                <h6 :class="['group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 whitespace-nowrap uppercase font-bold leading-tight', isAdminPortal ? 'text-[10px] tracking-[0.16em] text-muted-foreground' : 'text-[10px] tracking-[0.18em] text-muted-foreground']">
                    {{ item.heading }}
                </h6>
            </li>

            <li
                v-else
                :class="[
                    'nk-menu-item group/item py-0.5',
                    { active: isActive(item) && !customizing },
                    draggingKey === item.key ? 'opacity-40' : '',
                ]"
                @dragover="customizing && item.group ? dragOverRow($event, item, item.group) : null"
                @drop.prevent
            >
                <!-- While customising, a row is a handle and two arrows, not
                     a link: a nav item that both navigates and drags would
                     send someone to another page on a slightly slipped
                     click, all day long. -->
                <div
                    v-if="customizing && item.group"
                    :class="[
                        'flex items-center gap-1 rounded-lg border px-2 py-1.5 transition-colors',
                        movedKey === item.key
                            ? 'border-primary/40 bg-primary/10 '
                            : 'border-dashed border-border ',
                    ]"
                >
                    <span
                        class="flex size-7 shrink-0 cursor-grab items-center justify-center text-muted-foreground transition-colors hover:text-primary active:cursor-grabbing"
                        draggable="true"
                        aria-hidden="true"
                        @dragstart="onDragStart($event, item.key)"
                        @dragend="onDragEnd"
                    ><GripVertical class="h-4 w-4" /></span>

                    <span class="min-w-0 flex-1 truncate text-[13px] font-bold text-muted-foreground">{{ item.text }}</span>

                    <!-- Arrows are the primary control, not a fallback: HTML5
                         drag does not exist on touch, and they are what a
                         keyboard can reach. -->
                    <button
                        type="button"
                        class="flex size-6 shrink-0 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary disabled:opacity-25 "
                        :disabled="positionOf(item, item.group) === 0"
                        :aria-label="`Déplacer ${item.text} vers le haut`"
                        @click="moveRow(item, item.group, -1)"
                    ><ChevronUp class="h-3 w-3" /></button>
                    <button
                        type="button"
                        class="flex size-6 shrink-0 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary disabled:opacity-25 "
                        :disabled="positionOf(item, item.group) === siblings(item.group).length - 1"
                        :aria-label="`Déplacer ${item.text} vers le bas`"
                        @click="moveRow(item, item.group, 1)"
                    ><ChevronDown class="h-3 w-3" /></button>
                </div>

                <details v-else-if="item.children" :open="isActive(item)" class="group/site">
                    <!-- La géométrie est celle d'un `Link` du même rail, au
                         pixel près : padding, boîte d'icône et marge. Un
                         groupe posé en `ps-6` alors que ses voisins sont en
                         `px-2.5` décalait « Pharmacie » de 14 px vers la
                         droite et son libellé de 10 px de plus — une seule
                         ligne du menu ne tombait pas sur la même colonne que
                         les autres. -->
                    <summary :class="['nk-menu-link flex cursor-pointer list-none items-center font-heading font-bold tracking-snug transition-colors', isAdminPortal ? 'rounded-md px-3 py-2.5 hover:bg-accent ' : 'rounded-lg px-2.5 py-2', isAdminPortal && isActive(item) ? 'bg-primary/15 ring-1 ring-inset ring-primary/25' : '', !isAdminPortal && !isActive(item) ? 'hover:bg-accent/60' : '']">
                        <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground group-[.active]/item:text-primary', isAdminPortal ? '' : 'me-1']">
                            <component :is="item.icon" :class="isAdminPortal ? 'h-4 w-4' : 'h-[18px] w-[18px]'" />
                        </span>
                        <span :class="['group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 min-w-0 flex-1 truncate text-muted-foreground group-[.active]/item:text-primary', isAdminPortal ? '' : 'text-[13px]']">
                            {{ item.text }}
                        </span>
                        <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 ms-2 flex items-center gap-2">
                            <span v-if="item.integrationStatus" :class="['rounded border px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide', item.integrationStatus === 'CONFIGURED' ? 'border-emerald-500/30 text-emerald-500' : ' text-muted-foreground']">API</span>
                            <ChevronRight class="h-3.5 w-3.5 text-muted-foreground transition-transform group-open/site:rotate-90" />
                        </span>
                    </summary>
                    <!-- Le trait tombe au centre de l'icône du parent
                         (10 px de padding + la moitié d'une boîte de 32),
                         et le libellé d'un enfant reprend la colonne du
                         libellé parent. -->
                    <ul :class="['group-[&.is-compact:not(.has-hover)]/sidebar:hidden pb-1', isAdminPortal ? 'ms-8 border-s border-border ps-3 pe-1' : 'ms-[26px] border-s border-border ps-2 pe-1']">
                        <li v-for="child in item.children" :key="child.code">
                            <Link
                                :href="child.link"
                                :aria-current="isChildActive(item, child) ? 'page' : undefined"
                                :class="['flex items-center gap-2.5 rounded px-3 py-2 transition-colors', isAdminPortal ? 'text-xs' : 'text-[13px]', isChildActive(item, child) ? 'bg-primary/10 font-bold text-primary' : 'text-muted-foreground hover:bg-accent hover:text-foreground']"
                                @click="closeMobile"
                            >
                                <component :is="child.icon" v-if="child.icon && typeof child.icon !== 'string'" class="h-4 w-4 shrink-0" />
                                <span class="min-w-0 truncate">{{ child.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </details>

                <Link
                    v-else-if="item.link"
                    :href="item.link"
                    :aria-current="isActive(item) ? 'page' : undefined"
                    :title="item.text"
                    :class="[
                        'nk-menu-link nk-route-toggle relative flex items-center align-middle font-heading font-bold tracking-snug transition-colors group',
                        isAdminPortal ? 'rounded-md px-3 py-2.5' : 'rounded-lg px-2.5 py-2',
                        isActive(item) ? 'bg-primary/15 text-primary ring-1 ring-inset ring-primary/25' : '',
                        !isActive(item) ? 'hover:bg-accent/60' : '',
                    ]"
                    @click="closeMobile"
                >
                    <!-- L'état actif se repère à sa **position**, pas à sa
                         teinte. En thème sombre, le survol (`accent`, clarté
                         19 %) sortait plus clair que la sélection
                         (`primary/10`, ≈ 16 % sur ce fond) : deux taches
                         sombres impossibles à départager, et la mauvaise
                         l'emportait. Ce rail ne dépend d'aucune nuance. -->
                    <span
                        v-if="isActive(item)"
                        class="absolute inset-y-1 start-0 w-1 rounded-e-full bg-primary"
                        aria-hidden="true"
                    />
                    <span :class="['inline-flex h-8 w-8 flex-grow-0 flex-shrink-0 items-center justify-center rounded-md font-normal tracking-normal text-muted-foreground transition-colors group-[.active]/item:bg-primary/100/10 group-[.active]/item:text-primary group-hover:text-primary', isAdminPortal ? '' : 'me-1']">
                        <component :is="item.icon" :class="isAdminPortal ? 'h-4 w-4' : 'h-[18px] w-[18px]'" />
                    </span>
                    <span :class="['group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 min-w-0 flex-grow truncate whitespace-nowrap transition-all duration-300 group-[.active]/item:text-primary group-hover:text-primary', isAdminPortal ? 'text-[13px] text-muted-foreground' : 'text-[13px] text-muted-foreground']">
                        {{ item.text }}
                    </span>
                    <span v-if="isActive(item)" class="group-[&.is-compact:not(.has-hover)]/sidebar:hidden ms-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary shadow-[0_0_0_3px_rgba(14,165,233,0.12)]" aria-hidden="true" />
                </Link>

                <div
                    v-else
                    class="nk-menu-link relative flex cursor-default items-center rounded-lg px-2.5 py-2 font-heading font-bold tracking-snug opacity-60"
                >
                    <span class="me-1 inline-flex h-8 w-8 flex-grow-0 flex-shrink-0 items-center justify-center rounded-md font-normal tracking-normal text-muted-foreground">
                        <component :is="item.icon" class="h-[18px] w-[18px]" />
                    </span>
                    <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 flex-grow inline-flex items-center justify-between gap-2 whitespace-nowrap text-muted-foreground">
                        <span>{{ item.text }}</span>
                        <span class="rounded bg-muted px-1.5 py-0.5 text-xxs font-normal normal-case tracking-normal text-muted-foreground">
                            Bientôt
                        </span>
                    </span>
                </div>
            </li>
        </template>

        <!-- Tertiary on purpose. A bordered full-width block reads as a
             primary action and competes with the modules above it, in a
             menu where the modules are the only thing that matters. Off by
             default too: nothing here should move by accident. -->
        <li v-if="canCustomize" class="group-[&.is-compact:not(.has-hover)]/sidebar:hidden px-2 pt-2">
            <div class="flex items-center gap-0.5">
                <button
                    type="button"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded px-2 py-1 text-[11px] transition-colors',
                        customizing
                            ? 'font-bold text-primary '
                            : 'font-medium text-muted-foreground hover:bg-accent hover:text-foreground ',
                    ]"
                    :aria-pressed="customizing"
                    @click="customizing = !customizing"
                >
                    <component :is="customizing ? Check : ArrowUpDown" class="h-3.5 w-3.5" />{{ customizing ? 'Terminer' : 'Personnaliser l’ordre' }}
                </button>
                <button
                    v-if="customizing && hasStoredOrder"
                    type="button"
                    class="rounded px-2 py-1 text-[11px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground "
                    @click="resetAll"
                >Réinitialiser</button>
            </div>
            <!-- Only while customising: a permanent hint would be one more
                 thing to read every time the menu is opened. -->
            <p v-if="customizing" class="mt-1 px-2 text-[10px] leading-4 text-muted-foreground">
                Glissez la poignée ou utilisez les flèches. L’ordre est propre à votre compte et ne change aucun accès.
            </p>
        </li>
    </ul>
</template>
