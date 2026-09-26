<script setup>
import { computed, onMounted, ref } from 'vue';
import SimpleBar from 'simplebar-vue';
import { useResizeObserver } from '@vueuse/core';
import { usePage } from '@inertiajs/vue3';
import Menu from './Menu.vue';
import { ArrowLeft, Menu as MenuIcon } from 'lucide-vue-next';
import { useThemeStore } from '@/stores/theme';
import BrandLockup from './BrandLockup.vue';
import SidebarResizeHandle from './SidebarResizeHandle.vue';

const theme = useThemeStore();
const page = usePage();
const site = computed(() => page.props.site);

const visibility = defineModel('visibility');
const compact = defineModel('compact');
const width = defineModel('width', { type: Number, default: 288 });
const emit = defineEmits(['resizing']);

const mobile = ref(false);
const mouseEnter = ref(false);
const resizing = ref(false);

const setResizing = (value) => {
    resizing.value = value;
    emit('resizing', value);
};

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
            'nk-sidebar group/sidebar peer fixed w-72 xl:w-[var(--sidebar-width)] [&.is-compact:not(.has-hover)]:w-[74px] min-h-screen max-h-screen h-full start-0 top-0 z-[1031] transition-[transform,width] -translate-x-full rtl:translate-x-full xl:translate-x-0 xl:rtl:translate-x-0 [&.sidebar-visible]:translate-x-0': true,
            'duration-0 select-none': resizing,
            'duration-300': !resizing,
            'sidebar-visible': visibility,
            'nk-sidebar-mobile': mobile,
            'is-compact': compact,
            'has-hover': compact && mouseEnter,
            dark: theme.sidebar === 'dark',
        }"
    >
        <div class="relative flex h-16 min-w-full w-full items-center overflow-hidden border-b border-e border-border bg-card px-4 py-3">
            <span v-if="site?.type === 'admin'" class="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-primary-500 via-cyan-400 to-amber-300" />
            <div class="-ms-1 me-3">
                <div class="hidden xl:block">
                    <a
                        href="#sidebar"
                        class="sidebar-compact-toggle *:pointer-events-none inline-flex items-center isolate relative h-9 w-9 px-1.5 before:content-[''] before:absolute before:-z-[1] before:h-5 before:w-5 hover:before:h-10 hover:before:w-10 before:rounded-full before:opacity-0 hover:before:opacity-100 before:transition-all before:duration-300 before:-translate-x-1/2 before:-translate-y-1/2 before:top-1/2 before:left-1/2 before:bg-border "
                        @click.prevent="compact = !compact"
                    >
                        <MenuIcon class="h-5 w-5 text-muted-foreground" />
                    </a>
                </div>

                <div class="xl:hidden">
                    <button
                        type="button"
                        class="sidebar-toggle *:pointer-events-none inline-flex items-center isolate relative h-9 w-9 px-1.5 before:content-[''] before:absolute before:-z-[1] before:h-5 before:w-5 hover:before:h-10 hover:before:w-10 before:rounded-full before:opacity-0 hover:before:opacity-100 before:transition-all before:duration-300 before:-translate-x-1/2 before:-translate-y-1/2 before:top-1/2 before:left-1/2 before:bg-border rtl:-scale-x-100"
                        @click="visibility = !visibility"
                    >
                        <ArrowLeft class="h-5 w-5 text-muted-foreground" />
                    </button>
                </div>
            </div>

            <div class="relative flex min-w-0 flex-1">
                <BrandLockup class="transition-opacity duration-300 group-[&.is-compact:not(.has-hover)]/sidebar:opacity-0" />
            </div>
        </div>

        <div
            class="nk-sidebar-body max-h-full relative overflow-hidden w-full bg-card border-e border-border"
            @mouseenter="mouseEnter = true"
            @mouseleave="mouseEnter = false"
        >
            <div class="flex flex-col w-full h-[calc(100vh-theme(spacing.16))]">
                <SimpleBar :class="['h-full pb-10', site?.type === 'admin' ? 'pt-3' : 'pt-4']">
                    <Menu v-model:visibility="visibility" :compact="compact && !mouseEnter" />
                </SimpleBar>
            </div>
        </div>

        <SidebarResizeHandle
            v-model="width"
            :enabled="site?.type === 'admin' && !compact && !mobile"
            @resizing="setResizing"
        />
    </div>

    <div
        class="sidebar-toggle fixed inset-0 bg-slate-950 bg-opacity-20 z-[1030] opacity-0 invisible peer-[.sidebar-visible]:opacity-100 peer-[.sidebar-visible]:visible xl:!opacity-0 xl:!invisible"
        @click.prevent="visibility = false"
    />
</template>
