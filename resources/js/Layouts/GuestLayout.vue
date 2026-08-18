<script setup>
import { useThemeSync } from '@/composables/useThemeSync';

useThemeSync();
</script>

<template>
    <div class="nk-main">
        <div class="nk-wrap flex flex-col min-h-screen">
            <slot />
        </div>
    </div>
</template>
