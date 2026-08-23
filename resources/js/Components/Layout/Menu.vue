<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

const visibility = defineModel('visibility');

const page = usePage();
const { can } = usePermissions();

const clinicMenu = [
    { heading: 'Principal' },
    { icon: 'growth', text: 'Tableau de bord', link: '/' },
    { heading: 'Gestion clinique' },
    { icon: 'card-view', text: 'Réception', link: '/reception', permission: 'reception.view' },
    { icon: 'wallet', text: 'Caisse', link: '/cash', activeLinks: ['/cash', '/receipts'], permission: 'cash.view' },
    { icon: 'users', text: 'Patients', link: '/patients', permission: 'patients.view' },
    { icon: 'user-list', text: 'Médecine', link: '/medicine', permission: 'consultations.view' },
    { icon: 'user-check', text: 'Soins', link: '/care', permission: 'care.view' },
    { icon: 'grid-alt', text: 'Chirurgie', link: '/surgery', permission: 'surgery.view' },
    { icon: 'bag', text: 'Pharmacie', link: '/pharmacy', permission: 'pharmacy.view' },
    { heading: 'Gestion' },
    { icon: 'briefcase', text: 'Ressources humaines', link: '/administration', exact: true, permission: 'employees.view' },
    { icon: 'package', text: 'Logistique', link: '/logistics', permission: 'logistics.view' },
    { icon: 'shield-check', text: 'Gardiennage', link: '/reception/visitors', permission: 'guarding.view' },
    { icon: 'users', text: 'Utilisateurs & accès', link: '/administration/users', activeLinks: ['/administration/users'], permission: 'users.view' },
    { icon: 'setting-alt', text: 'Référentiels & tarifs', link: '/administration/catalog', activeLinks: ['/administration/catalog'], permission: 'catalog.items.view' },
];

const adminMenu = computed(() => [
    { heading: 'Vue d’ensemble' },
    { icon: 'growth', text: 'Tableau de bord global', link: '/' },
    { heading: 'Sites' },
    ...(page.props.adminNavigation ?? []).map((site) => ({
        icon: 'building',
        text: site.name,
        permission: 'sites.view',
        activeLinks: [`/super-admin/sites/${site.code}`],
        children: site.modules,
        integrationStatus: site.integration_status,
    })),
    { heading: 'Finance' },
    { icon: 'wallet', text: 'Rapports financiers', link: '/super-admin/workspaces/finance', permission: 'reports.financial.view' },
    { heading: 'Administration' },
    { icon: 'briefcase', text: 'Ressources humaines', link: '/super-admin/workspaces/hr', permission: 'employees.view' },
    { icon: 'package', text: 'Logistique & équipements', link: '/super-admin/workspaces/logistics', permission: 'logistics.view' },
    { icon: 'shield-check', text: 'Gardiennage', link: '/super-admin/workspaces/guarding', permission: 'guarding.view' },
    { icon: 'list-index', text: 'Désignations & tarifs', link: '/super-admin/workspaces/tariffs', permission: 'catalog.items.view' },
    { heading: 'Accès & système' },
    { icon: 'users', text: 'Gestion utilisateurs', link: '/super-admin/workspaces/users', permission: 'users.view' },
    { icon: 'shield-check', text: 'Rôles & permissions', link: '/super-admin/workspaces/roles', permission: 'roles.view' },
    { icon: 'setting-alt', text: 'Paramètres', link: '/super-admin/workspaces/settings', permission: 'settings.view' },
    { icon: 'history', text: 'Audit & APIs', link: '/super-admin/workspaces/audit', permission: 'audit.view' },
]);

const rawMenu = computed(() => page.props.site?.type === 'admin' ? adminMenu.value : clinicMenu);

// A heading is only rendered when at least one item under it is visible —
// Every operational item is gated by a dynamic permission. The only item
// intentionally shared by all active accounts is the dashboard. Modules that
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
    <ul class="nk-menu">
        <template v-for="(item, index) in menuData" :key="index">
            <li
                v-if="item.heading"
                class="relative first:pt-1 pt-10 pb-2 px-6 before:absolute before:h-px before:w-full before:start-0 before:top-1/2 before:bg-gray-200 dark:before:bg-gray-900 first:before:hidden before:opacity-0 group-[&.is-compact:not(.has-hover)]/sidebar:before:opacity-100"
            >
                <h6 class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 text-slate-500 dark:text-slate-300 whitespace-nowrap uppercase font-bold text-xs tracking-relaxed leading-tight">
                    {{ item.heading }}
                </h6>
            </li>

            <li
                v-else
                :class="['nk-menu-item py-0.5 group/item', { active: isActive(item) }]"
            >
                <details v-if="item.children" :open="isActive(item)" class="group/site">
                    <summary class="nk-menu-link flex cursor-pointer list-none items-center py-2.5 ps-6 pe-5 font-heading font-bold tracking-snug">
                        <span class="w-9 shrink-0 text-slate-400 group-[.active]/item:text-primary-500">
                            <Icon class="text-2xl leading-none" :name="item.icon" />
                        </span>
                        <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 min-w-0 flex-1 truncate text-slate-600 dark:text-slate-300 group-[.active]/item:text-primary-500">
                            {{ item.text }}
                        </span>
                        <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 ms-2 flex items-center gap-2">
                            <span
                                :class="['h-1.5 w-1.5 rounded-full', item.integrationStatus === 'CONFIGURED' ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700']"
                                :title="item.integrationStatus === 'CONFIGURED' ? 'API configurée' : 'API à configurer'"
                            />
                            <Icon class="text-sm text-slate-400 transition-transform group-open/site:rotate-90" name="chevron-right" />
                        </span>
                    </summary>
                    <ul class="group-[&.is-compact:not(.has-hover)]/sidebar:hidden pb-2 ps-[60px] pe-4">
                        <li v-for="child in item.children" :key="child.code">
                            <Link
                                :href="child.link"
                                :class="['block rounded px-3 py-1.5 text-xs transition-colors', isChildActive(child) ? 'bg-gray-100 font-bold text-primary-600 dark:bg-gray-900 dark:text-primary-400' : 'text-slate-500 hover:bg-gray-50 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-gray-900 dark:hover:text-slate-300']"
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
                    class="nk-menu-link nk-route-toggle flex relative items-center align-middle py-2.5 ps-6 pe-10 font-heading font-bold tracking-snug group"
                    @click="closeMobile"
                >
                    <span class="font-normal tracking-normal w-9 inline-flex flex-grow-0 flex-shrink-0 text-slate-400 group-[.active]/item:text-primary-500 group-hover:text-primary-500">
                        <Icon class="text-2xl leading-none text-current transition-all duration-300" :name="item.icon" />
                    </span>
                    <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 flex-grow-1 inline-block whitespace-nowrap transition-all duration-300 text-slate-600 dark:text-slate-300 group-[.active]/item:text-primary-500 group-hover:text-primary-500">
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
