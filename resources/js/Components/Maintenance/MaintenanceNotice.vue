<script setup>
import { computed } from 'vue';
import { Clock, Construction } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { returnLabel } from '@/utilities/maintenance';

/**
 * ADR-193 — le message d'un site en maintenance : celui que voient ses comptes,
 * et son aperçu dans le portail. Un seul composant, pour que l'aperçu ne mente
 * jamais sur ce qui s'affichera.
 *
 * Le message est du texte simple : ses retours à la ligne sont gardés, rien
 * n'est interprété comme du HTML.
 */
const props = defineProps({
    title: { type: String, required: true },
    message: { type: String, default: '' },
    endsAt: { type: String, default: null },
    /** Plus petit, dans l'aperçu du portail. */
    compact: { type: Boolean, default: false },
});

const back = computed(() => returnLabel(props.endsAt));
</script>

<template>
    <div :class="cn('flex flex-col items-center text-center', compact ? 'gap-3' : 'gap-4')" role="status">
        <span :class="cn('grid shrink-0 place-items-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300', compact ? 'h-11 w-11' : 'h-14 w-14')" aria-hidden="true">
            <Construction :class="compact ? 'h-5 w-5' : 'h-7 w-7'" />
        </span>
        <h1 :class="cn('font-heading font-bold tracking-tight text-foreground', compact ? 'text-lg' : 'text-2xl sm:text-[28px]')">{{ title }}</h1>
        <p v-if="message" :class="cn('max-w-prose whitespace-pre-line text-muted-foreground', compact ? 'text-sm leading-6' : 'text-base leading-7')">{{ message }}</p>
        <p :class="cn('inline-flex items-center gap-2 rounded-full border border-border bg-muted/50 px-3 py-1 font-medium text-foreground', compact ? 'text-xs' : 'text-sm')">
            <Clock class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />{{ back }}
        </p>
    </div>
</template>
