<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

const visibility = defineModel('visibility');

const page = usePage();
const { can } = usePermissions();

const rawMenu = [
    { heading: 'Principal' },
    { icon: 'growth', text: 'Tableau de bord', link: '/' },
    { heading: 'Gestion clinique' },
    { icon: 'card-view', text: 'Réception', link: '/reception', permission: 'episodes.create' },
    { icon: 'users', text: 'Patients & Caisse', link: '/patients', activeLinks: ['/patients', '/cash', '/receipts'], permission: 'patients.view' },
    { icon: 'user-list', text: 'Médecine' },
    { icon: 'grid-alt', text: 'Chirurgie' },
    { icon: 'table-view', text: 'Laboratoire' },
    { icon: 'cart', text: 'Pharmacie' },
    { heading: 'Gestion' },
    { icon: 'file-docs', text: 'Administration', permission: 'users.manage' },
    { icon: 'setting-alt', text: 'Super Administration' },
];

// A heading is only rendered when at least one item under it is visible —
// items gated by a `permission` the user does not hold are dropped entirely
// (not just disabled), items with no `permission` requirement are always
// shown (visually present but inactive until their module/route exists).
const menuData = computed(() => {
    const visible = [];
    let pendingHeading = null;

    for (const item of rawMenu) {
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
    if (!item.link) {
        return false;
    }

    if (item.activeLinks) {
        return item.activeLinks.some((link) => page.url.startsWith(link));
    }

    if (item.link === '/') {
        return page.url === '/';
    }

    return page.url.startsWith(item.link);
};

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
                <h6 class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 text-slate-400 dark:text-slate-300 whitespace-nowrap uppercase font-bold text-xs tracking-relaxed leading-tight">
                    {{ item.heading }}
                </h6>
            </li>

            <li
                v-else
                :class="['nk-menu-item py-0.5 group/item', { active: isActive(item) }]"
            >
                <Link
                    v-if="item.link"
                    :href="item.link"
                    class="nk-menu-link nk-route-toggle flex relative items-center align-middle py-2.5 ps-6 pe-10 font-heading font-bold tracking-snug group"
                    @click="closeMobile"
                >
                    <span class="font-normal tracking-normal w-9 inline-flex flex-grow-0 flex-shrink-0 text-slate-400 group-[.active]/item:text-primary-500 group-hover:text-primary-500">
                        <Icon class="text-2xl leading-none text-current transition-all duration-300" :name="item.icon" />
                    </span>
                    <span class="group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0 flex-grow-1 inline-block whitespace-nowrap transition-all duration-300 text-slate-600 dark:text-slate-500 group-[.active]/item:text-primary-500 group-hover:text-primary-500">
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
