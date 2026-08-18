<script setup>
import { onMounted, ref, watch } from 'vue';

import Sidebar from '@/Components/Layout/Sidebar.vue';
import Header from '@/Components/Layout/Header.vue';
import Footer from '@/Components/Layout/Footer.vue';

import { useThemeStore } from '@/stores/theme';

defineProps({
    container: {
        type: Boolean,
        default: false,
    },
});

const theme = useThemeStore();

const sidebarVisibility = ref(false);
const sidebarCompact = ref(false);

const applyTheme = () => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('dark', theme.mode === 'dark');
    document.body.setAttribute('dir', theme.direction);
};

onMounted(applyTheme);

watch(() => [theme.mode, theme.direction], applyTheme);
</script>

<template>
    <div class="nk-main">
        <Sidebar v-model:visibility="sidebarVisibility" v-model:compact="sidebarCompact" />

        <div class="nk-wrap xl:ps-72 [&>.nk-header]:xl:start-72 [&>.nk-header]:xl:w-[calc(100%-theme(spacing.72))] peer-[&.is-compact:not(.has-hover)]:xl:ps-[74px] peer-[&.is-compact:not(.has-hover)]:[&>.nk-header]:xl:start-[74px] peer-[&.is-compact:not(.has-hover)]:[&>.nk-header]:xl:w-[calc(100%-74px)] flex flex-col min-h-screen transition-all duration-300">
            <Header v-model:visibility="sidebarVisibility" />

            <div class="nk-content mt-16 px-1.5 sm:px-5 py-6 sm:py-8">
                <div :class="{ container: true, 'max-w-none': !container }">
                    <slot />
                </div>
            </div>

            <Footer />
        </div>
    </div>
</template>
