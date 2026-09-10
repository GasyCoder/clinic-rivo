<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

const visibility = defineModel('visibility');

const page = usePage();
const { can } = usePermissions();
const isAdminPortal = computed(() => page.props.site?.type === 'admin');
const overviewLabel = computed(() => (
    page.props.auth?.user?.role?.code === 'SUPER_ADMIN' ? 'Dashboard' : 'Vue d’ensemble'
));
const clinicMenu = computed(() => [
    { heading: 'Principal' },
    { icon: 'growth', text: overviewLabel.value, link: '/' },
    { heading: 'Gestion clinique' },
    { icon: 'card-view', text: 'Réception', link: '/reception', permission: 'reception.view' },
    { icon: 'wallet', text: 'Caisse', link: '/cash', activeLinks: ['/cash', '/receipts'], permission: 'cash.view' },
    { icon: 'users', text: 'Patients', link: '/patients', permission: 'patients.view' },
    { icon: 'activity', text: 'Médecine', link: '/medicine', permission: 'consultations.view' },
    { icon: 'activity', text: 'Laboratoire', link: '/laboratory', permission: 'laboratory_orders.view' },
    // care.view alone also powers the read-only projection embedded in
    // Médecine/Chirurgie's own dossier pages (ADR-048/054) — gating on
    // care.update instead keeps the full Soins queue's menu entry for the
    // role that actually operates it (NURSE), without exposing it to roles
    // that only ever consult that projection.
    { icon: 'user-check', text: 'Soins', link: '/care', permission: 'care.update' },
    { icon: 'heart', text: 'Maternité', link: '/maternity', permission: 'maternity.view' },
    { icon: 'masks', text: 'Chirurgie', link: '/surgery', permission: 'surgery.view' },
    { icon: 'shield-check', text: 'Anesthésie', link: '/anesthesia', permission: 'anesthesia.view' },
    {
        icon: 'capsule',
        text: 'Pharmacie',
        link: can('pharmacy.counter_sales.create') ? '/pharmacy/counter-sales/create' : '/pharmacy',
        activeLinks: ['/pharmacy'],
        permission: 'pharmacy.view',
    },
    { heading: 'Gestion' },
    { icon: 'briefcase', text: 'Ressources humaines', link: '/administration', exact: true, permission: 'employees.view' },
    { icon: 'package', text: 'Logistique', link: '/logistics', permission: 'logistics.view' },
    { icon: 'shield-check', text: 'Gardiennage', link: '/reception/visitors', permission: 'guarding.view' },
    { icon: 'users', text: 'Utilisateurs & accès', link: '/administration/users', activeLinks: ['/administration/users'], permission: 'users.view' },
    { icon: 'setting-alt', text: 'Référentiels & tarifs', link: '/administration/catalog', activeLinks: ['/administration/catalog'], permission: 'catalog.items.view' },
    { icon: 'activity', text: 'Catalogue analyses', link: '/administration/analyses', activeLinks: ['/administration/analyses'], permission: 'analysis_catalog.view' },
    { icon: 'trash', text: 'Corbeille', link: '/trash', permission: 'trash.view' },
]);

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
    <ul :class="['nk-menu pb-5', isAdminPortal && 'px-3']">
        <template v-for="(item, index) in menuData" :key="index">
            <li
                v-if="item.heading"
                :class="[
                    'relative first:pt-1 pb-2 before:absolute before:h-px before:w-full before:start-0 before:top-1/2 before:bg-gray-200 dark:before:bg-gray-900 first:before:hidden before:opacity-0 group-[&.is-compact:not(.has-hover)]/sidebar:before:opacity-100',
                    isAdminPortal ? 'px-3 pt-6' : 'px-6 pt-10',
                ]"
            >
                <h6 :class="['group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 whitespace-nowrap uppercase font-bold leading-tight', isAdminPortal ? 'text-[10px] tracking-[0.16em] text-slate-400 dark:text-slate-500' : 'text-xs tracking-relaxed text-slate-500 dark:text-slate-300']">
                    {{ item.heading }}
                </h6>
            </li>

            <li
                v-else
                :class="['nk-menu-item group/item', isAdminPortal ? 'py-0.5' : 'py-0.5', { active: isActive(item) }]"
            >
                <details v-if="item.children" :open="isActive(item)" class="group/site">
                    <summary :class="['nk-menu-link flex cursor-pointer list-none items-center font-heading font-bold tracking-snug transition-colors', isAdminPortal ? 'rounded-md px-3 py-2.5 hover:bg-gray-100 dark:hover:bg-white/5' : 'py-2.5 ps-6 pe-5', isAdminPortal && isActive(item) ? 'bg-primary-500/10' : '']">
                        <span :class="['shrink-0 text-slate-400 group-[.active]/item:text-primary-500', isAdminPortal ? 'flex h-8 w-8 items-center justify-center' : 'w-9']">
                            <Icon :class="isAdminPortal ? 'text-lg leading-none' : 'text-2xl leading-none'" :name="item.icon" />
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
                    :class="[
                        'nk-menu-link nk-route-toggle relative flex items-center align-middle font-heading font-bold tracking-snug transition-colors group',
                        isAdminPortal ? 'rounded-md px-3 py-2.5' : 'py-2.5 ps-6 pe-10',
                        isAdminPortal && isActive(item) ? 'bg-primary-500/10 text-primary-500' : '',
                        isAdminPortal && !isActive(item) ? 'hover:bg-gray-100 dark:hover:bg-white/5' : '',
                    ]"
                    @click="closeMobile"
                >
                    <span :class="['inline-flex flex-grow-0 flex-shrink-0 items-center font-normal tracking-normal text-slate-400 group-[.active]/item:text-primary-500 group-hover:text-primary-500', isAdminPortal ? 'h-8 w-8 justify-center' : 'w-9']">
                        <Icon :class="isAdminPortal ? 'text-lg leading-none text-current' : 'text-2xl leading-none text-current transition-all duration-300'" :name="item.icon" />
                    </span>
                    <span :class="['group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 inline-block flex-grow whitespace-nowrap transition-all duration-300 group-[.active]/item:text-primary-500 group-hover:text-primary-500', isAdminPortal ? 'text-[13px] text-slate-600 dark:text-slate-300' : 'text-slate-600 dark:text-slate-300']">
                        {{ item.text }}
                    </span>
                </Link>

                <div
                    v-else
                    class="nk-menu-link flex relative items-center align-middle py-2.5 ps-6 pe-10 font-heading font-bold tracking-snug cursor-default opacity-60"
                >
                    <span class="font-normal tracking-normal w-9 inline-flex flex-grow-0 flex-shrink-0 text-slate-400">
                        <Icon class="text-2xl leading-none text-current" :name="item.icon" />
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
