<script setup>
import { onMounted, watch } from 'vue';
import { useThemeStore } from '@/stores/theme';

const theme = useThemeStore();

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

onMounted(() => {
    applyTheme();
});

watch(
    () => [theme.mode, theme.direction],
    () => {
        applyTheme();
    }
);
</script>

<template>
    <div class="nk-main">
        <div class="flex min-h-screen flex-col">
            <main class="nk-content flex-1 px-1.5 py-6 sm:px-5 sm:py-8">
                <div class="container max-w-none">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
