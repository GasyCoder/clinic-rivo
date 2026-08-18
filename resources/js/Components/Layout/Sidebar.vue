<script setup>
import { Link } from '@inertiajs/vue3';
import Menu from './Menu.vue';
import { useThemeStore } from '@/stores/theme';

const theme = useThemeStore();

const visibility = defineModel('visibility', {
    default: false,
});

const compact = defineModel('compact', {
    default: false,
});

const toggleCompact = () => {
    compact.value = !compact.value;
};

const closeMobile = () => {
    visibility.value = false;
};
</script>

<template>
    <aside
        class="fixed start-0 top-0 z-[1031] h-screen
               border-e border-gray-200 bg-white
               transition-all duration-300
               dark:border-gray-900 dark:bg-gray-950"
        :class="[
            visibility
                ? 'translate-x-0'
                : '-translate-x-full xl:translate-x-0',

            compact
                ? 'w-72 xl:w-[74px]'
                : 'w-72',

            theme.sidebar === 'dark'
                ? 'dark'
                : '',
        ]"
    >
        <!-- Brand -->
        <div
            class="flex h-16 items-center border-b
                   border-gray-200 px-4
                   dark:border-gray-900"
        >
            <button
                type="button"
                class="me-3 hidden h-9 w-9 flex-none
                       items-center justify-center rounded-full
                       text-slate-600 transition
                       hover:bg-gray-100
                       dark:text-slate-300
                       dark:hover:bg-gray-900
                       xl:inline-flex"
                @click="toggleCompact"
            >
                <em class="icon ni ni-menu text-2xl" />
            </button>

            <Link
                href="/"
                class="min-w-0"
                @click="closeMobile"
            >
                <div
                    v-if="!compact"
                    class="leading-tight"
                >
                    <div
                        class="font-heading text-xl font-bold
                               text-slate-700 dark:text-white"
                    >
                        Clinique
                    </div>

                    <div
                        class="truncate text-xxs
                               text-slate-400"
                    >
                        Saint Georges
                    </div>
                </div>

                <div
                    v-else
                    class="hidden font-heading text-xl
                           font-bold text-primary-600 xl:block"
                >
                    R
                </div>
            </Link>

            <button
                type="button"
                class="ms-auto inline-flex h-9 w-9
                       items-center justify-center rounded-full
                       text-slate-600 hover:bg-gray-100
                       dark:text-slate-300
                       dark:hover:bg-gray-900 xl:hidden"
                @click="closeMobile"
            >
                <em class="icon ni ni-cross text-xl" />
            </button>
        </div>

        <!-- Menu -->
        <div
            class="h-[calc(100vh-4rem)] overflow-y-auto
                   overflow-x-hidden"
        >
            <Menu
                v-model:visibility="visibility"
                :compact="compact"
            />
        </div>
    </aside>

    <!-- Mobile backdrop -->
    <button
        v-if="visibility"
        type="button"
        aria-label="Fermer le menu"
        class="fixed inset-0 z-[1030]
               bg-slate-950/30 xl:hidden"
        @click="closeMobile"
    />
</template>
