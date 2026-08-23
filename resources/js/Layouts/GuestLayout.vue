<script setup>
import { useThemeSync } from '@/composables/useThemeSync';
import Icon from '@/Components/UI/Icon.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

const { theme } = useThemeSync();
</script>

<template>
    <div class="nk-main">
        <ToastContainer />
        <div class="nk-wrap flex flex-col min-h-screen">
            <!-- bottom-start: top-end is the mobile drawer toggle
                 (IdentityPanel.vue), and the identity panel itself owns the
                 whole end/right half of the screen from lg: up (z-[999],
                 opaque) — end-5 would sit underneath it and be unclickable. -->
            <button
                type="button"
                class="fixed bottom-5 start-5 z-20 inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 bg-white text-slate-500 transition-colors duration-300 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-400 dark:hover:text-primary-500"
                :title="theme.mode === 'dark' ? 'Mode clair' : 'Mode sombre'"
                @click="theme.updateMode"
            >
                <Icon :name="theme.mode === 'dark' ? 'sun' : 'moon'" class="text-lg leading-none" />
            </button>

            <slot />
        </div>
    </div>
</template>
