<script setup>
import { computed } from 'vue';
import { UsersRound } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Card from '@/Components/Shadcn/Card.vue';

const props = defineProps({
    demographics: {
        type: Object,
        default: () => ({ total: 0, segments: [], children: { total: 0, boys: 0, girls: 0 } }),
    },
});

const colors = {
    men: '#2563eb',
    women: '#db2777',
    children: '#f59e0b',
    unclassified: '#94a3b8',
};

const total = computed(() => Number(props.demographics?.total ?? 0));
const segments = computed(() => (props.demographics?.segments ?? []).map((segment) => ({
    ...segment,
    value: Number(segment.value ?? 0),
    color: colors[segment.key] ?? colors.unclassified,
    percent: total.value > 0 ? (Number(segment.value ?? 0) / total.value) * 100 : 0,
})));

const donutBackground = computed(() => {
    if (!total.value || !segments.value.length) return '#e2e8f0';

    let cursor = 0;
    const stops = segments.value.map((segment) => {
        const start = cursor;
        cursor += segment.percent;

        return `${segment.color} ${start}% ${cursor}%`;
    });

    return `conic-gradient(${stops.join(', ')})`;
});

const chartLabel = computed(() => total.value
    ? segments.value.map((segment) => `${segment.label} : ${segment.value}`).join(', ')
    : 'Aucun patient enregistré');

const percentLabel = (percent) => new Intl.NumberFormat('fr-FR', {
    maximumFractionDigits: percent < 10 ? 1 : 0,
}).format(percent);
</script>

<template>
    <Card class="overflow-hidden" aria-labelledby="patient-demographics-title">
        <header class="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <h2 id="patient-demographics-title" class="font-heading text-base font-bold text-foreground">Profil des patients</h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Répartition des dossiers actifs par âge et sexe.</p>
            </div>
            <Badge variant="secondary">{{ total }}</Badge>
        </header>

        <div v-if="total" class="p-5">
            <div
                class="relative mx-auto aspect-square w-44 rounded-full shadow-inner sm:w-48 xl:w-44"
                :style="{ background: donutBackground }"
                role="img"
                :aria-label="chartLabel"
            >
                <div class="absolute inset-[22%] flex flex-col items-center justify-center rounded-full border border-border bg-card shadow-sm">
                    <strong class="font-heading text-3xl font-bold leading-none tabular-nums text-foreground">{{ total }}</strong>
                    <span class="mt-1 text-[11px] font-medium text-muted-foreground">patients</span>
                </div>
            </div>

            <ul class="mt-5 space-y-2.5">
                <li v-for="segment in segments" :key="segment.key" class="flex items-center gap-2.5 text-xs">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: segment.color }" />
                    <span class="min-w-0 flex-1 truncate text-muted-foreground">{{ segment.label }}</span>
                    <strong class="tabular-nums text-foreground">{{ segment.value }}</strong>
                    <span class="w-10 text-end tabular-nums text-muted-foreground">{{ percentLabel(segment.percent) }} %</span>
                </li>
            </ul>

            <div v-if="demographics.children?.total" class="mt-4 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2.5 text-xs dark:border-amber-900 dark:bg-amber-950/20">
                <div class="flex items-center justify-between gap-3">
                    <span class="font-semibold text-amber-900 dark:text-amber-200">Détail des enfants</span>
                    <span class="text-amber-800/80 dark:text-amber-300">{{ demographics.children.boys }} garçon{{ demographics.children.boys > 1 ? 's' : '' }} · {{ demographics.children.girls }} fille{{ demographics.children.girls > 1 ? 's' : '' }}</span>
                </div>
            </div>
        </div>

        <div v-else class="flex flex-col items-center justify-center px-6 py-10 text-center">
            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-muted text-muted-foreground ring-1 ring-inset ring-border"><UsersRound class="h-5 w-5" /></span>
            <h3 class="mt-3 text-sm font-bold text-foreground">Aucun patient enregistré</h3>
            <p class="mt-1 text-xs text-muted-foreground">La répartition apparaîtra après la création du premier dossier.</p>
        </div>
    </Card>
</template>
