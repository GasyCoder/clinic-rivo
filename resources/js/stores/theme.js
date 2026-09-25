import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { usePreferredDark, useStorage } from '@vueuse/core';

/** Les trois apparences proposées : clair, celle de l'appareil, sombre. */
export const THEME_MODES = ['light', 'system', 'dark'];

/**
 * L'apparence choisie par l'utilisateur, gardée sur ce poste.
 *
 * `initOnMounted` : le stockage n'est lu qu'après l'hydratation. Les pages
 * sont rendues par le serveur, qui ne connaît pas ce choix ; le lire pendant
 * le rendu ferait diverger le HTML et Vue ne le répare pas. La classe `dark`
 * est de toute façon posée avant le premier affichage par le script de
 * `app.blade.php` : aucun éclair de thème clair.
 */
export const useThemeStore = defineStore('theme', () => {
    const mode = useStorage('rivo:theme:mode', 'light', undefined, { initOnMounted: true });
    const sidebar = useStorage('rivo:theme:sidebar', ref('dark'));
    const direction = useStorage('rivo:theme:direction', ref('ltr'));

    // « Système » suit l'appareil en direct : changer le thème de l'ordinateur
    // change celui de RIVO sans recharger.
    const prefersDark = usePreferredDark();
    const resolved = computed(() => (mode.value === 'dark' || (mode.value === 'system' && prefersDark.value) ? 'dark' : 'light'));

    const setMode = (value) => {
        mode.value = THEME_MODES.includes(value) ? value : 'light';
    };

    const updateSidebar = () => {
        sidebar.value = sidebar.value === 'light' ? 'dark' : 'light';
    };

    const updateDirection = () => {
        direction.value = direction.value === 'ltr' ? 'rtl' : 'ltr';
    };

    return {
        mode,
        resolved,
        sidebar,
        direction,
        setMode,
        updateSidebar,
        updateDirection,
    };
});
