import { ref } from 'vue';
import { defineStore } from 'pinia';
import { useStorage } from '@vueuse/core';

/**
 * Remembers which clinic a visitor picked on the staff gateway, purely as a
 * client-side convenience (localStorage) — never sent anywhere, never a
 * source of authorization. The gateway has no session at that point, so
 * there is nothing to persist server-side; this only saves a click on a
 * return visit and is always overridable by picking a different site.
 */
export const useSitePreferenceStore = defineStore('sitePreference', () => {
    const preferredSiteCode = useStorage('rivo:site:preferred', ref(null));

    const remember = (code) => {
        preferredSiteCode.value = code;
    };

    const forget = () => {
        preferredSiteCode.value = null;
    };

    return {
        preferredSiteCode,
        remember,
        forget,
    };
});
