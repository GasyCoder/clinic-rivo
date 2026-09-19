<script setup>
import { ChevronRight } from 'lucide-vue-next';
import { followUpShort, pillLabel, stepIcon, stepStyle, stepTitle } from '@/utilities/episodePathway';

/**
 * La frise compacte du parcours d'un passage, pour le dossier du patient
 * (ADR-117). Les mêmes étapes que la liste du « Détail du passage » — servies
 * par Laravel, jamais recomposées ici — réduites à une pastille chacune ; le
 * détail de chaque étape est dans son infobulle.
 */
defineProps({
    steps: { type: Array, required: true },
});
</script>

<template>
    <ol class="flex flex-wrap items-center gap-x-1 gap-y-2" aria-label="Parcours du passage">
        <template v-for="(step, index) in steps" :key="step.key">
            <li
                :title="stepTitle(step)"
                :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold', stepStyle(step).pill]"
            >
                <component :is="stepIcon(step)" class="h-3 w-3 shrink-0" aria-hidden="true" />
                <span>{{ pillLabel(step) }}</span>
                <span v-if="followUpShort(step)" class="font-medium opacity-80">· {{ followUpShort(step) }}</span>
            </li>
            <li v-if="index < steps.length - 1" aria-hidden="true">
                <ChevronRight class="h-3 w-3 shrink-0 text-muted-foreground/50" />
            </li>
        </template>
    </ol>
</template>
