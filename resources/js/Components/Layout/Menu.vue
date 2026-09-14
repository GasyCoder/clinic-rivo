<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';
import { CLINIC_WORKSPACES, ROLE_FOCUS, WORKSPACE_GROUPS } from '@/utilities/clinicWorkspaces';

const visibility = defineModel('visibility');

const page = usePage();
const { can } = usePermissions();
const isAdminPortal = computed(() => page.props.site?.type === 'admin');
const currentUser = computed(() => page.props.auth?.user ?? null);
const roleLabel = computed(() => currentUser.value?.role?.name ?? 'Accès clinique');
const profileLabel = computed(() => currentUser.value?.professional_profile?.name ?? null);
const roleFocus = computed(() => ROLE_FOCUS[currentUser.value?.role?.code] ?? null);
const overviewLabel = computed(() => (
    page.props.auth?.user?.role?.code === 'SUPER_ADMIN' ? 'Dashboard' : 'Vue d’ensemble'
));
// Built from the shared workspace list, so the sidebar and the overview can
// never disagree on which modules exist or which permission opens them.
const clinicMenu = computed(() => {
    const primaryWorkspace = roleFocus.value?.primary?.link
        ? [...CLINIC_WORKSPACES]
            .filter((workspace) => roleFocus.value.primary.link === workspace.link
                || roleFocus.value.primary.link.startsWith(`${workspace.link}/`))
            .sort((left, right) => right.link.length - left.link.length)[0]
        : null;
    const focusOrder = [primaryWorkspace?.key, ...(roleFocus.value?.shortcuts ?? [])].filter(Boolean);
    const items = CLINIC_WORKSPACES.map((workspace, originalIndex) => ({
        key: workspace.key,
        icon: workspace.icon,
        text: workspace.text,
        link: workspace.resolveLink ? workspace.resolveLink(can) : workspace.link,
        activeLinks: workspace.activeLinks,
        exact: workspace.exact,
        permission: workspace.permission,
        group: workspace.group,
        originalIndex,
    })).sort((left, right) => {
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

    return [
        { heading: 'Principal' },
        { icon: 'growth', text: overviewLabel.value, link: '/' },
        ...Object.entries(WORKSPACE_GROUPS).flatMap(([group, heading]) => [
            { heading },
            ...items.filter((item) => item.group === group),
        ]),
    ];
});

const adminMenu = computed(() => [
    { heading: 'Vue centrale' },
    { icon: 'growth', text: overviewLabel.value, link: '/' },
    { heading: 'Établissements' },
    ...(page.props.adminNavigation ?? []).map((site) => ({
        icon: 'building',
        text: site.name,
        permission: 'sites.view',
        activeLinks: [`/super-admin/sites/${site.code}`],
        children: site.modules,
        integrationStatus: site.integration_status,
    })),
    { heading: 'Finance & caisse' },
    { icon: 'wallet', text: 'Caisses des sites', link: '/super-admin/cash-registers', permission: 'cash_registers.view' },
    { icon: 'card-view', text: 'Modes de paiement', link: '/super-admin/payment-methods', permission: 'payment_methods.view' },
    { icon: 'wallet', text: 'Rapports financiers', link: '/super-admin/workspaces/finance', permission: 'reports.financial.view' },
    { heading: 'Référentiels & stocks' },
    { icon: 'list-index', text: 'Tarifs & mutuelles', link: '/super-admin/workspaces/tariffs', permission: 'catalog.items.view' },
    { icon: 'file-text', text: 'Canevas de documents', link: '/super-admin/workspaces/document-templates', permission: 'document_templates.view' },
    { icon: 'activity', text: 'Catalogue des analyses', link: '/super-admin/analyses', permission: 'analysis_catalog.view' },
    { icon: 'capsule', text: 'Stock médicaments', link: '/super-admin/stock', permission: 'stock.view' },
    { icon: 'map-pin', text: 'Adresses & localités', link: '/super-admin/addresses', permission: 'address_entries.view' },
    { heading: 'Organisation' },
    { icon: 'briefcase', text: 'Ressources humaines', link: '/super-admin/workspaces/hr', permission: 'employees.view' },
    { icon: 'package', text: 'Logistique & équipements', link: '/super-admin/workspaces/logistics', permission: 'logistics.view' },
    { icon: 'shield-check', text: 'Gardiennage', link: '/super-admin/workspaces/guarding', permission: 'guarding.view' },
    { heading: 'Sécurité & système' },
    { icon: 'shield-check', text: 'Rôles & permissions', link: '/super-admin/workspaces/roles', permission: 'roles.view' },
    { icon: 'trash', text: 'Corbeille', link: '/super-admin/trash', permission: 'trash.view' },
    { icon: 'setting-alt', text: 'Paramètres', link: '/super-admin/workspaces/settings', permission: 'settings.view' },
    { icon: 'history', text: 'Audit & APIs', link: '/super-admin/workspaces/audit', permission: 'audit.view' },
]);

const rawMenu = computed(() => page.props.site?.type === 'admin' ? adminMenu.value : clinicMenu.value);

// A heading is only rendered when at least one item under it is visible —
// Every operational item is gated by a dynamic permission. The only item
// intentionally shared by all active accounts is the overview. Modules that
// have no implemented permission catalog yet (Laboratoire, Pharmacie) are not
// shown at all; they will be added when their routes and permissions exist.
const menuData = computed(() => {
    const visible = [];
    let pendingHeading = null;

    for (const item of rawMenu.value) {
        if (item.heading) {
            pendingHeading = item;
            continue;
        }

        if (item.permission && !can(item.permission)) {
            continue;
        }

        if (pendingHeading) {
            visible.push(pendingHeading);
            pendingHeading = null;
        }

        visible.push(item);
    }

    return visible;
});

const visibleWorkspaceCount = computed(() => menuData.value.filter((item) => !item.heading && item.link !== '/').length);

const isActive = (item) => {
    if (item.activeLinks) {
        return item.activeLinks.some((link) => page.url.startsWith(link));
    }

    if (!item.link) {
        return false;
    }

    if (item.exact) {
        return page.url.split('?')[0] === item.link;
    }

    if (item.link === '/') {
        return page.url === '/';
    }

    return page.url.startsWith(item.link);
};

const isChildActive = (child) => page.url === child.link
    || (child.code === 'OVERVIEW' && page.url.split('?')[0] === child.link.split('?')[0]);

const closeMobile = () => {
    visibility.value = false;
};
</script>

<template>
    <ul class="nk-menu px-3 pb-5">
        <li v-if="!isAdminPortal" class="group-[&.is-compact:not(.has-hover)]/sidebar:hidden px-1 pb-1 pt-3">
            <div class="relative overflow-hidden rounded-lg border border-slate-200/10 bg-slate-800/40 px-3 py-3 shadow-sm dark:border-white/5 dark:bg-white/[0.035]">
                <span class="absolute inset-y-0 start-0 w-0.5 bg-primary-500" />
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-500/10 text-primary-400 ring-1 ring-inset ring-primary-500/20">
                        <Icon class="text-lg" name="shield-check" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-bold text-slate-200 dark:text-white">{{ profileLabel || roleLabel }}</p>
                        <p class="mt-0.5 truncate text-[10px] text-slate-400">{{ profileLabel ? roleLabel : 'Espace professionnel' }} · {{ visibleWorkspaceCount }} module{{ visibleWorkspaceCount > 1 ? 's' : '' }}</p>
                    </div>
                </div>
            </div>
        </li>

        <template v-for="(item, index) in menuData" :key="index">
            <li
                v-if="item.heading"
                :class="[
                    'relative first:pt-1 pb-2 before:absolute before:h-px before:w-full before:start-0 before:top-1/2 before:bg-gray-200 dark:before:bg-gray-900 first:before:hidden before:opacity-0 group-[&.is-compact:not(.has-hover)]/sidebar:before:opacity-100',
                    isAdminPortal ? 'px-3 pt-6' : 'px-2 pt-6',
                ]"
            >
                <h6 :class="['group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 whitespace-nowrap uppercase font-bold leading-tight', isAdminPortal ? 'text-[10px] tracking-[0.16em] text-slate-400 dark:text-slate-500' : 'text-[10px] tracking-[0.18em] text-slate-500 dark:text-slate-400']">
                    {{ item.heading }}
                </h6>
            </li>

            <li
                v-else
                :class="['nk-menu-item group/item', isAdminPortal ? 'py-0.5' : 'py-0.5', { active: isActive(item) }]"
            >
                <details v-if="item.children" :open="isActive(item)" class="group/site">
                    <summary :class="['nk-menu-link flex cursor-pointer list-none items-center font-heading font-bold tracking-snug transition-colors', isAdminPortal ? 'rounded-md px-3 py-2.5 hover:bg-gray-100 dark:hover:bg-white/5' : 'py-2.5 ps-6 pe-5', isAdminPortal && isActive(item) ? 'bg-primary-500/10' : '']">
                        <span :class="['shrink-0 text-slate-400 group-[.active]/item:text-primary-500', isAdminPortal ? 'flex h-8 w-8 items-center justify-center' : 'flex h-8 w-8 items-center justify-center rounded-md bg-slate-500/5']">
                            <Icon :class="isAdminPortal ? 'text-lg leading-none' : 'text-xl leading-none'" :name="item.icon" />
                        </span>
                        <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 min-w-0 flex-1 truncate text-slate-600 dark:text-slate-300 group-[.active]/item:text-primary-500">
                            {{ item.text }}
                        </span>
                        <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 ms-2 flex items-center gap-2">
                            <span :class="['rounded border px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide', item.integrationStatus === 'CONFIGURED' ? 'border-emerald-500/30 text-emerald-500' : 'border-slate-300 text-slate-400 dark:border-slate-700']">API</span>
                            <Icon class="text-sm text-slate-400 transition-transform group-open/site:rotate-90" name="chevron-right" />
                        </span>
                    </summary>
                    <ul :class="['group-[&.is-compact:not(.has-hover)]/sidebar:hidden pb-2', isAdminPortal ? 'ms-8 border-s border-slate-200/20 ps-3 pe-1' : 'ps-[60px] pe-4']">
                        <li v-for="child in item.children" :key="child.code">
                            <Link
                                :href="child.link"
                                :class="['block rounded px-3 py-2 text-xs transition-colors', isChildActive(child) ? 'bg-primary-500/10 font-bold text-primary-500' : 'text-slate-500 hover:bg-white/5 hover:text-slate-700 dark:text-slate-400 dark:hover:text-white']"
                                @click="closeMobile"
                            >
                                {{ child.label }}
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
                        isActive(item) ? 'bg-primary-500/10 text-primary-500 ring-1 ring-inset ring-primary-500/10' : '',
                        !isActive(item) ? 'hover:bg-slate-500/[0.07] dark:hover:bg-white/5' : '',
                    ]"
                    @click="closeMobile"
                >
                    <span :class="['inline-flex h-8 w-8 flex-grow-0 flex-shrink-0 items-center justify-center rounded-md font-normal tracking-normal text-slate-400 transition-colors group-[.active]/item:bg-primary-500/10 group-[.active]/item:text-primary-500 group-hover:text-primary-500', isAdminPortal ? '' : 'me-1']">
                        <Icon :class="isAdminPortal ? 'text-lg leading-none text-current' : 'text-xl leading-none text-current transition-all duration-300'" :name="item.icon" />
                    </span>
                    <span :class="['group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 min-w-0 flex-grow truncate whitespace-nowrap transition-all duration-300 group-[.active]/item:text-primary-500 group-hover:text-primary-500', isAdminPortal ? 'text-[13px] text-slate-600 dark:text-slate-300' : 'text-[13px] text-slate-600 dark:text-slate-300']">
                        {{ item.text }}
                    </span>
                    <span v-if="isActive(item)" class="group-[&.is-compact:not(.has-hover)]/sidebar:hidden ms-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary-500 shadow-[0_0_0_3px_rgba(14,165,233,0.12)]" aria-hidden="true" />
                </Link>

                <div
                    v-else
                    class="nk-menu-link relative flex cursor-default items-center rounded-lg px-2.5 py-2 font-heading font-bold tracking-snug opacity-60"
                >
                    <span class="me-1 inline-flex h-8 w-8 flex-grow-0 flex-shrink-0 items-center justify-center rounded-md font-normal tracking-normal text-slate-400">
                        <Icon class="text-xl leading-none text-current" :name="item.icon" />
                    </span>
                    <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 flex-grow inline-flex items-center justify-between gap-2 whitespace-nowrap text-slate-500 dark:text-slate-500">
                        <span>{{ item.text }}</span>
                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xxs font-normal normal-case tracking-normal text-slate-400 dark:bg-gray-900">
                            Bientôt
                        </span>
                    </span>
                </div>
            </li>
        </template>
    </ul>
</template>
