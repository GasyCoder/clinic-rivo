import { ref } from 'vue';
import { defineStore } from 'pinia';
import { useStorage } from '@vueuse/core';

export const useThemeStore = defineStore('theme', () => {
    const mode = useStorage('rivo:theme:mode', ref('light'));
    const sidebar = useStorage('rivo:theme:sidebar', ref('dark'));
    const direction = useStorage('rivo:theme:direction', ref('ltr'));

    const updateMode = () => {
        mode.value = mode.value === 'light' ? 'dark' : 'light';
    };

    const updateSidebar = () => {
        sidebar.value = sidebar.value === 'light' ? 'dark' : 'light';
    };

    const updateDirection = () => {
        direction.value = direction.value === 'ltr' ? 'rtl' : 'ltr';
    };

    return {
        mode,
        sidebar,
        direction,
        updateMode,
        updateSidebar,
        updateDirection,
    };
});
