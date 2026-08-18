<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const visibility = defineModel('visibility');

defineProps({
    compact: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();

const sections = [
    {
        heading: 'Principal',
        items: [
            {
                text: 'Tableau de bord',
                icon: 'growth',
                href: '/',
            },
        ],
    },
    {
        heading: 'Gestion clinique',
        items: [
            {
                text: 'Réception & Caisse',
                icon: 'card-view',
                disabled: true,
            },
            {
                text: 'Patients',
                icon: 'users',
                disabled: true,
            },
            {
                text: 'Médecine',
                icon: 'user-list',
                disabled: true,
            },
            {
                text: 'Chirurgie',
                icon: 'grid-alt',
                disabled: true,
            },
            {
                text: 'Laboratoire',
                icon: 'table-view',
                disabled: true,
            },
            {
                text: 'Pharmacie',
                icon: 'cart',
                disabled: true,
            },
        ],
    },
    {
        heading: 'Gestion',
        items: [
            {
                text: 'Administration',
                icon: 'file-docs',
                disabled: true,
            },
            {
                text: 'Super Administration',
                icon: 'setting-alt',
                disabled: true,
            },
        ],
    },
];

const isActive = (item) => {
    if (!item.href) {
        return false;
    }

    if (item.href === '/') {
        return page.url === '/';
    }

    return page.url.startsWith(item.href);
};

const closeSidebar = () => {
    visibility.value = false;
};
</script>

<template>
    <nav class="py-4">
        <template
            v-for="(section, sectionIndex) in sections"
            :key="sectionIndex"
        >
            <div
                v-if="!compact"
                class="px-6 pb-2 pt-5 first:pt-1"
            >
                <h6
                    class="font-heading text-xs font-bold uppercase
                           tracking-relaxed text-slate-400"
                >
                    {{ section.heading }}
                </h6>
            </div>

            <ul class="space-y-1 px-3">
                <li
                    v-for="item in section.items"
                    :key="item.text"
                >
                    <Link
                        v-if="!item.disabled"
                        :href="item.href"
                        @click="closeSidebar"
                        class="group flex min-h-11 items-center rounded-md
                               px-3 py-2.5 transition-colors"
                        :class="[
                            isActive(item)
                                ? 'bg-primary-50 text-primary-600 dark:bg-primary-950/40'
                                : 'text-slate-600 hover:bg-gray-100 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-gray-900'
                        ]"
                    >
                        <span
                            class="inline-flex w-9 flex-none items-center"
                        >
                            <em
                                :class="[
                                    'icon ni text-2xl',
                                    `ni-${item.icon}`,
                                ]"
                            />
                        </span>

                        <span
                            v-if="!compact"
                            class="font-heading text-sm font-bold"
                        >
                            {{ item.text }}
                        </span>
                    </Link>

                    <div
                        v-else
                        class="flex min-h-11 cursor-default items-center
                               rounded-md px-3 py-2.5 text-slate-400
                               opacity-70 dark:text-slate-600"
                    >
                        <span
                            class="inline-flex w-9 flex-none items-center"
                        >
                            <em
                                :class="[
                                    'icon ni text-2xl',
                                    `ni-${item.icon}`,
                                ]"
                            />
                        </span>

                        <div
                            v-if="!compact"
                            class="flex min-w-0 flex-1 items-center justify-between gap-2"
                        >
                            <span class="font-heading text-sm font-bold">
                                {{ item.text }}
                            </span>

                            <span
                                class="rounded bg-gray-100 px-1.5 py-0.5
                                       text-xxs dark:bg-gray-900"
                            >
                                À venir
                            </span>
                        </div>
                    </div>
                </li>
            </ul>
        </template>
    </nav>
</template>
