<script setup>
import { computed } from 'vue';
import { CirclePause, MonitorCog, Rows2, Rows3, Rows4, Sparkles } from 'lucide-vue-next';
import OptionTile from '@/Components/Settings/OptionTile.vue';

/**
 * ADR-191 — le repère d'une option des réglages « Affichage avancé » : il montre
 * ce que l'option change plutôt qu'un symbole arbitraire — des « Aa » à la taille
 * choisie, des coins plus ou moins arrondis, un disque plus ou moins contrasté,
 * des lignes plus ou moins serrées. Décoratif : le nom de l'option est écrit à côté.
 */
const props = defineProps({
    field: { type: String, required: true },
    value: { type: String, required: true },
});

const ICONS = {
    ui_density: { compact: Rows4, default: Rows3, comfortable: Rows2 },
    ui_motion: { system: MonitorCog, reduce: CirclePause, full: Sparkles },
};

const icon = computed(() => ICONS[props.field]?.[props.value] ?? null);

/** 14 px → 9 px … 18 px → 13 px : l'écart entre les tailles reste lisible dans la vignette. */
const glyphSize = computed(() => `${Math.max(8, Number(props.value) - 5)}px`);
const RADIUS = { square: '0px', default: '4px', round: '7px' };
const CONTRAST = { standard: 0.35, high: 0.7, max: 1 };
</script>

<template>
    <OptionTile>
        <span v-if="field === 'ui_font_size'" class="font-semibold leading-none" :style="{ fontSize: glyphSize }">Aa</span>
        <span v-else-if="field === 'ui_radius'" class="h-4 w-4 border-2 border-current" :style="{ borderRadius: RADIUS[value] ?? RADIUS.default }" />
        <span v-else-if="field === 'ui_contrast'" class="relative h-3.5 w-3.5 overflow-hidden rounded-full border-2 border-current" :style="{ opacity: 0.45 + 0.55 * (CONTRAST[value] ?? 0.35) }">
            <span class="absolute inset-y-0 end-0 w-1/2 bg-current" :style="{ opacity: CONTRAST[value] ?? 0.35 }" />
        </span>
        <component :is="icon" v-else-if="icon" class="h-3.5 w-3.5" />
    </OptionTile>
</template>
