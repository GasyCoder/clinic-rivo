<script setup>
import { useThemeSync } from '@/composables/useThemeSync';
import ThemeModeSwitcher from '@/Components/Layout/ThemeModeSwitcher.vue';
import ToastContainer from '@/Components/UI/ToastContainer.vue';

useThemeSync();
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <ToastContainer />

        <!-- Clair, Système (l'appareil) ou Sombre, avant même la connexion. -->
        <ThemeModeSwitcher variant="floating" class="fixed bottom-5 start-5 z-30" />

        <slot />
    </div>
</template>
