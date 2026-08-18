<script setup>
import { onMounted, ref, watch } from 'vue';

import Sidebar from '@/Components/Layout/Sidebar.vue';
import Header from '@/Components/Layout/Header.vue';
import Footer from '@/Components/Layout/Footer.vue';

import { useThemeStore } from '@/stores/theme';

const theme = useThemeStore();

const sidebarVisibility = ref(false);
const sidebarCompact = ref(false);

const applyTheme = () => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle(
        'dark',
        theme.mode === 'dark'
    );

    document.documentElement.setAttribute(
        'dir',
        theme.direction
    );
};

onMounted(applyTheme);

watch(
    () => [theme.mode, theme.direction],
    applyTheme
);
</script>

<template>
    <div
        class="min-h-screen bg-gray-50
               transition-colors dark:bg-gray-1000"
    >
        <Sidebar
            v-model:visibility="sidebarVisibility"
            v-model:compact="sidebarCompact"
        />

        <div
            class="flex min-h-screen min-w-0
                   flex-col transition-all duration-300"
            :class="
                sidebarCompact
                    ? 'xl:ps-[74px]'
                    : 'xl:ps-72'
            "
        >
            <Header
                v-model:visibility="sidebarVisibility"
            />

            <main
                class="nk-content flex-1
                       px-4 py-6 sm:px-6 sm:py-8"
            >
                <div class="container max-w-none">
                    <slot />
                </div>
            </main>

            <Footer />
        </div>
    </div>
</template>
