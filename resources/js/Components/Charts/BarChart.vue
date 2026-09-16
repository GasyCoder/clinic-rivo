<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/cn';

/**
 * Un histogramme horizontal — comparer des grandeurs nommées.
 *
 * Horizontal et non vertical : les libellés sont des noms de service
 * (« Laboratoire », « Maternité »), et verticalement ils se chevauchent ou
 * s'inclinent. Chaque barre porte son chiffre en clair à côté d'elle : la
 * longueur donne l'ordre de grandeur, le nombre donne la valeur.
 */
const props = defineProps({
    /** `[{ key, label, value, hint?, tone? }]` */
    items: { type: Array, default: () => [] },
    format: { type: Function, default: (value) => new Intl.NumberFormat('fr-FR').format(value) },
    emptyLabel: { type: String, default: 'Aucune donnée sur la période.' },
    class: { type: String, default: '' },
});

const TONES = {
    primary: 'bg-primary',
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    sky: 'bg-sky-500',
    rose: 'bg-rose-500',
};

/**
 * L'échelle part du plus grand, jamais de la somme : sur cinq files dont
 * une écrase les autres, des barres proportionnelles au total seraient
 * toutes invisibles.
 */
const max = computed(() => Math.max(1, ...props.items.map((item) => Number(item.value ?? 0))));

const bars = computed(() => props.items.map((item) => ({
    key: item.key ?? item.label,
    label: item.label,
    hint: item.hint ?? '',
    value: Number(item.value ?? 0),
    width: `${Math.max(Number(item.value ?? 0) > 0 ? 2 : 0, (Number(item.value ?? 0) / max.value) * 100)}%`,
    tone: TONES[item.tone] ?? TONES.primary,
})));
</script>

<template>
    <div :class="cn('space-y-3', props.class)">
        <div v-for="bar in bars" :key="bar.key">
            <div class="flex items-baseline justify-between gap-3">
                <span class="min-w-0 truncate text-sm font-semibold text-foreground">{{ bar.label }}</span>
                <span class="shrink-0 text-sm font-bold tabular-nums text-foreground">{{ format(bar.value) }}</span>
            </div>
            <div class="mt-1 h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                <div :class="cn('h-full rounded-full transition-[width]', bar.tone)" :style="{ width: bar.width }" />
            </div>
            <p v-if="bar.hint" class="mt-0.5 text-[11px] text-muted-foreground">{{ bar.hint }}</p>
        </div>

        <p v-if="! bars.length" class="text-sm text-muted-foreground">{{ emptyLabel }}</p>
    </div>
</template>
