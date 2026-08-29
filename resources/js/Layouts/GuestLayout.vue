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
            <button
                type="button"
                class="fixed bottom-5 start-5 z-30 inline-flex h-10 w-10 items-center justify-center rounded-md border border-white/35 bg-primary-950/55 text-white shadow-sm transition-colors duration-300 hover:bg-primary-950/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                :title="theme.mode === 'dark' ? 'Mode clair' : 'Mode sombre'"
                :aria-label="theme.mode === 'dark' ? 'Activer le mode clair' : 'Activer le mode sombre'"
                @click="theme.updateMode"
            >
                <Icon :name="theme.mode === 'dark' ? 'sun' : 'moon'" class="text-lg leading-none" />
            </button>

            <slot />
        </div>
    </div>
</template>
