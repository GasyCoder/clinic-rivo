<script setup>
/**
 * ADR-191 — la vignette d'un thème : sa moitié claire et sa moitié sombre, chacune
 * sur son arrière-plan avec sa couleur d'accentuation. Décorative : le nom du
 * thème, écrit à côté, dit tout ce qu'elle montre.
 */
defineProps({
    /** `{ light: { primary, background }, dark: { primary, background } }`, en hexadécimal. */
    colors: { type: Object, required: true },
});

const MODES = ['light', 'dark'];
</script>

<template>
    <span class="inline-flex h-5 w-9 shrink-0 overflow-hidden rounded-md border border-black/10 shadow-sm dark:border-white/15" aria-hidden="true">
        <span
            v-for="mode in MODES"
            :key="mode"
            class="flex flex-1 items-center justify-center"
            :style="{ backgroundColor: colors[mode]?.background }"
        >
            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: colors[mode]?.primary }" />
        </span>
    </span>
</template>
