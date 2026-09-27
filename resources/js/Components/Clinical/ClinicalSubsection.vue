<script setup>
import { computed } from 'vue';

/**
 * Un bloc de champs dans une étape clinique : une icône qui dit de quoi il
 * s'agit, un titre, une phrase facultative, et à droite ce que le bloc veut
 * mettre en avant (un total, une pastille). Tokens shadcn seulement (ADR-099).
 *
 * Né pour l'Anesthésie (ADR-048), partagé depuis avec la Maternité (ADR-205),
 * qui teinte ses icônes en rose comme le reste de son espace.
 */
const props = defineProps({
    icon: { type: [Object, Function], default: null },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    bodyClass: { type: String, default: 'p-4' },
    /** `primary` | `rose` */
    tone: { type: String, default: 'primary' },
});

const TONES = {
    primary: 'bg-primary/10 text-primary',
    rose: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
};
const iconTone = computed(() => TONES[props.tone] ?? TONES.primary);
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-border bg-card">
        <header class="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-2.5">
            <span v-if="icon" :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md', iconTone]">
                <component :is="icon" class="h-4 w-4" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>
                <p v-if="description" class="text-xs leading-5 text-muted-foreground">{{ description }}</p>
            </div>
            <slot name="aside" />
        </header>
        <div :class="bodyClass"><slot /></div>
    </section>
</template>
