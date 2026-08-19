<script setup>
import { computed, onMounted, ref } from 'vue';
import SimpleBar from 'simplebar-vue';
import { useResizeObserver } from '@vueuse/core';
import { Link, usePage } from '@inertiajs/vue3';
import Menu from './Menu.vue';
import Icon from '@/Components/UI/Icon.vue';
import { useThemeStore } from '@/stores/theme';

const theme = useThemeStore();
const page = usePage();
const site = computed(() => page.props.site);

const visibility = defineModel('visibility');
const compact = defineModel('compact');

const mobile = ref(false);
const mouseEnter = ref(false);

onMounted(() => {
    useResizeObserver(document.documentElement, (entries) => {
        if (entries[0].contentRect.width < 1280) {
            setTimeout(() => {
                mobile.value = true;
                compact.value = false;
            }, 2000);
        } else {
            mobile.value = false;
            visibility.value = false;
        }
    });
});
</script>

<template>
    <div
        :class="{
            'nk-sidebar group/sidebar peer fixed w-72 [&.is-compact:not(.has-hover)]:w-[74px] min-h-screen max-h-screen overflow-hidden h-full start-0 top-0 z-[1031] transition-[transform,width] duration-300 -translate-x-full rtl:translate-x-full xl:translate-x-0 xl:rtl:translate-x-0 [&.sidebar-visible]:translate-x-0': true,
            'sidebar-visible': visibility,
            'nk-sidebar-mobile': mobile,
            'is-compact': compact,
            'has-hover': compact && mouseEnter,
            dark: theme.sidebar === 'dark',
        }"
    >
        <div class="flex items-center min-w-full w-72 h-16 border-b border-e bg-white dark:bg-gray-950 border-gray-200 dark:border-gray-900 px-6 py-3 overflow-hidden">
            <div class="-ms-1 me-4">
                <div class="hidden xl:block">
                    <a
                        href="#sidebar"
                        class="sidebar-compact-toggle *:pointer-events-none inline-flex items-center isolate relative h-9 w-9 px-1.5 before:content-[''] before:absolute before:-z-[1] before:h-5 before:w-5 hover:before:h-10 hover:before:w-10 before:rounded-full before:opacity-0 hover:before:opacity-100 before:transition-all before:duration-300 before:-translate-x-1/2 before:-translate-y-1/2 before:top-1/2 before:left-1/2 before:bg-gray-200 dark:before:bg-gray-900"
                        @click.prevent="compact = !compact"
                    >
                        <Icon class="text-2xl text-slate-600 dark:text-slate-300" name="menu" />
                    </a>
                </div>

                <div class="xl:hidden">
                    <button
                        type="button"
                        class="sidebar-toggle *:pointer-events-none inline-flex items-center isolate relative h-9 w-9 px-1.5 before:content-[''] before:absolute before:-z-[1] before:h-5 before:w-5 hover:before:h-10 hover:before:w-10 before:rounded-full before:opacity-0 hover:before:opacity-100 before:transition-all before:duration-300 before:-translate-x-1/2 before:-translate-y-1/2 before:top-1/2 before:left-1/2 before:bg-gray-200 dark:before:bg-gray-900 rtl:-scale-x-100"
                        @click="visibility = !visibility"
                    >
                        <Icon name="arrow-left" class="text-2xl text-slate-600 dark:text-slate-300" />
                    </button>
                </div>
            </div>

            <div class="relative flex flex-shrink-0 min-w-0">
                <Link
                    href="/"
                    class="relative inline-flex flex-col leading-tight transition-opacity duration-300 group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0"
                >
                    <span class="font-heading text-sm font-bold leading-tight text-slate-700 dark:text-white truncate">{{ site.brand }}</span>
                    <span v-if="site.name" class="truncate text-xxs text-slate-400 uppercase tracking-wide">{{ site.name }}</span>
                </Link>
            </div>
        </div>

        <div
            class="nk-sidebar-body max-h-full relative overflow-hidden w-full bg-white dark:bg-gray-950 border-e border-gray-200 dark:border-gray-900"
            @mouseenter="mouseEnter = true"
            @mouseleave="mouseEnter = false"
        >
            <div class="flex flex-col w-full h-[calc(100vh-theme(spacing.16))]">
                <SimpleBar class="h-full pt-4 pb-10">
                    <Menu v-model:visibility="visibility" />
                </SimpleBar>
            </div>
        </div>
    </div>

    <div
        class="sidebar-toggle fixed inset-0 bg-slate-950 bg-opacity-20 z-[1030] opacity-0 invisible peer-[.sidebar-visible]:opacity-100 peer-[.sidebar-visible]:visible xl:!opacity-0 xl:!invisible"
        @click.prevent="visibility = false"
    />
</template>
