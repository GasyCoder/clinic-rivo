<script setup>
import { useThemeSync } from '@/composables/useThemeSync';
import Icon from '@/Components/UI/Icon.vue';

const { theme } = useThemeSync();
</script>

<template>
    <div class="nk-main">
        <div class="nk-wrap flex flex-col min-h-screen">
            <button
                type="button"
                class="fixed end-5 top-5 z-10 inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 bg-white text-slate-500 transition-colors duration-300 hover:text-primary-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-400 dark:hover:text-primary-500"
                :title="theme.mode === 'dark' ? 'Mode clair' : 'Mode sombre'"
                @click="theme.updateMode"
            >
                <Icon :name="theme.mode === 'dark' ? 'sun' : 'moon'" class="text-lg leading-none" />
            </button>

            <slot />
        </div>
    </div>
</template>
