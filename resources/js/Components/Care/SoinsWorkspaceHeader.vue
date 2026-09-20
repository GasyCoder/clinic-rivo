<script setup>
import { cn } from '@/lib/cn';

/**
 * L'en-tête des trois espaces du module Soins (ADR-134, ADR-135).
 *
 * Infirmière, Maternité et Anesthésie avaient chacun le leur — une icône, un
 * titre, une phrase — écrit trois fois avec des tailles et des couleurs qui
 * divergeaient. Il vit ici une seule fois ; chaque espace ne choisit que sa
 * teinte, son icône et ses mots. La recherche et les actions se glissent à
 * droite par les slots, sans que l'en-tête ait à les connaître.
 */
defineProps({
    icon: { type: [Object, Function], required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    /** `primary` | `rose` | `violet` */
    tone: { type: String, default: 'primary' },
});

const TONES = {
    primary: { chip: 'bg-primary/10 text-primary', eyebrow: 'text-primary' },
    rose: { chip: 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-300', eyebrow: 'text-rose-600 dark:text-rose-300' },
    violet: { chip: 'bg-violet-50 text-violet-700 dark:bg-violet-950/30 dark:text-violet-300', eyebrow: 'text-violet-600 dark:text-violet-300' },
};
</script>

<template>
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="flex items-start gap-3">
            <span :class="cn('grid h-11 w-11 shrink-0 place-items-center rounded-lg', (TONES[tone] ?? TONES.primary).chip)">
                <component :is="icon" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <p v-if="eyebrow" :class="cn('text-xs font-bold uppercase tracking-wide', (TONES[tone] ?? TONES.primary).eyebrow)">{{ eyebrow }}</p>
                <h1 class="font-heading text-2xl font-bold tracking-tight text-foreground">{{ title }}</h1>
                <p v-if="description" class="mt-1 max-w-3xl text-sm leading-5 text-muted-foreground">{{ description }}</p>
            </div>
        </div>
        <div v-if="$slots.default" class="flex w-full flex-col gap-2 sm:flex-row sm:items-center lg:w-auto lg:justify-end">
            <slot />
        </div>
    </header>
</template>
