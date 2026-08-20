import { onMounted, watch } from 'vue';
import { useThemeStore } from '@/stores/theme';

/**
 * Applies the persisted theme (dark mode class on <html>, dir on <body>) to
 * the document. Must be called from every layout — the theme preference is
 * global (Pinia + localStorage) but the DOM side effect only runs where this
 * composable is invoked, so a layout that skips it silently ignores dark mode.
 */
export function useThemeSync() {
    const theme = useThemeStore();

    const applyTheme = () => {
        if (typeof document === 'undefined') {
            return;
        }

        document.documentElement.classList.toggle('dark', theme.mode === 'dark');
        document.body.setAttribute('dir', theme.direction);
    };

    onMounted(applyTheme);

    watch(() => [theme.mode, theme.direction], applyTheme);

    return { theme };
}
