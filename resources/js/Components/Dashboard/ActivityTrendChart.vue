<script setup>
import { computed, ref } from 'vue';
import { BarChart3, LineChart } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';

const props = defineProps({
    trend: {
        type: Object,
        default: () => ({ dates: [], series: [] }),
    },
    // Le portail affiche la même courbe sur trente jours et pour plusieurs
    // sites : le titre appartient donc à l'appelant, pas au graphique.
    title: { type: String, default: 'Activité des 7 derniers jours' },
    description: { type: String, default: 'Patients et activités visibles selon vos permissions.' },
    compact: { type: Boolean, default: false },
});

const chart = {
    width: 820,
    height: 240,
    left: 48,
    right: 18,
    top: 18,
    bottom: 38,
};

const colors = {
    navy: '#1f5f8b',
    ocean: '#2f83a8',
    cyan: '#0891a6',
    green: '#16836b',
    yellow: '#b7791f',
};

const dates = computed(() => props.trend?.dates ?? []);
const allSeries = computed(() => props.trend?.series ?? []);
const viewMode = ref('bars');

/** Series the reader switched off from the legend; purely visual. */
const hidden = ref(new Set());
const toggle = (key) => {
    const next = new Set(hidden.value);
    next.has(key) ? next.delete(key) : next.add(key);
    hidden.value = next;
};
const series = computed(() => allSeries.value.filter((item) => !hidden.value.has(item.key)));

/** Seven empty days deserve one sentence, not a 280px blank plot. */
const hasActivity = computed(() => allSeries.value.some((item) => (item.total ?? 0) > 0));
const plotWidth = chart.width - chart.left - chart.right;
const plotHeight = chart.height - chart.top - chart.bottom;
const bucketWidth = computed(() => plotWidth / Math.max(dates.value.length, 1));

/**
 * Un libellé sur N, jamais tous.
 *
 * Le graphique a été dessiné pour sept jours. Le portail lui en envoie
 * trente ou quatre-vingt-dix (ADR-102), et les trente dates se sont
 * superposées en une bouillie illisible — « Mar 1Mer 19Jeu 20 ». On en garde
 * une douzaine au plus, en comptant **depuis la fin** pour que le dernier
 * jour, celui qu'on regarde en premier, soit toujours écrit.
 */
const MAX_LABELS = 12;

const labelStride = computed(() => Math.max(1, Math.ceil(dates.value.length / MAX_LABELS)));

const isLabelVisible = (index) => index === dates.value.length - 1
    || (dates.value.length - 1 - index) % labelStride.value === 0;

/**
 * « Mer 16 » sur une semaine, « 16/09 » au-delà : sur trois mois, le jour de
 * la semaine ne situe plus rien, et le mois devient l'information utile.
 */
const dateLabels = computed(() => dates.value.map((date) => {
    const parsed = new Date(`${date}T12:00:00`);

    if (labelStride.value > 1) {
        return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' }).format(parsed);
    }

    const weekday = new Intl.DateTimeFormat('fr-FR', { weekday: 'short' })
        .format(parsed)
        .replace('.', '');
    const day = new Intl.DateTimeFormat('fr-FR', { day: 'numeric' }).format(parsed);

    return `${weekday} ${day}`;
}));

const highestValue = computed(() => Math.max(
    0,
    ...series.value.flatMap((item) => item.values ?? []),
));

const tickStep = computed(() => {
    const rawStep = Math.max(1, highestValue.value) / 4;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const normalized = rawStep / magnitude;
    const multiplier = normalized <= 1
        ? 1
        : normalized <= 2
            ? 2
            : normalized <= 2.5
                ? 2.5
                : normalized <= 5
                    ? 5
                    : 10;

    return Math.max(1, multiplier * magnitude);
});

const chartMaximum = computed(() => Math.max(
    tickStep.value,
    Math.ceil(highestValue.value / tickStep.value) * tickStep.value,
));

const yTicks = computed(() => {
    const values = [];

    for (let value = 0; value <= chartMaximum.value; value += tickStep.value) {
        values.push(value);
    }

    return values;
});

const xPosition = (index) => {
    if (dates.value.length <= 1) return chart.left;

    return chart.left + (index * plotWidth) / (dates.value.length - 1);
};

const histogramBarWidth = computed(() => Math.max(
    3,
    Math.min(18, (bucketWidth.value - 18) / Math.max(series.value.length, 1)),
));
const histogramGroupWidth = computed(() => histogramBarWidth.value * series.value.length);
const histogramX = (dateIndex, seriesIndex) => (
    chart.left
    + (bucketWidth.value * (dateIndex + 0.5))
    - (histogramGroupWidth.value / 2)
    + (seriesIndex * histogramBarWidth.value)
);
const histogramLabelX = (index) => chart.left + (bucketWidth.value * (index + 0.5));

const yPosition = (value) => chart.top + plotHeight - ((value / chartMaximum.value) * plotHeight);

const points = (item) => (item.values ?? [])
    .map((value, index) => `${xPosition(index)},${yPosition(value)}`)
    .join(' ');

const color = (tone) => colors[tone] ?? colors.navy;
</script>

<template>
    <Card class="overflow-hidden">
        <header class="border-b border-border px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-heading text-base font-bold text-foreground">{{ title }}</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ description }}</p>
                </div>
                <div v-if="hasActivity" class="inline-flex items-center rounded-lg bg-muted p-1" role="group" aria-label="Type de graphique">
                    <Button
                        type="button"
                        :variant="viewMode === 'bars' ? 'secondary' : 'ghost'"
                        size="xs"
                        :aria-pressed="viewMode === 'bars'"
                        class="shadow-none"
                        @click="viewMode = 'bars'"
                    >
                        <BarChart3 class="h-3.5 w-3.5" />Histogramme
                    </Button>
                    <Button
                        type="button"
                        :variant="viewMode === 'lines' ? 'secondary' : 'ghost'"
                        size="xs"
                        :aria-pressed="viewMode === 'lines'"
                        class="shadow-none"
                        @click="viewMode = 'lines'"
                    >
                        <LineChart class="h-3.5 w-3.5" />Courbes
                    </Button>
                </div>
            </div>

            <div v-if="allSeries.length" class="mt-3 flex flex-wrap items-center gap-1.5">
                <Button
                    v-for="item in allSeries"
                    :key="item.key"
                    type="button"
                    variant="outline"
                    size="xs"
                    :aria-pressed="!hidden.has(item.key)"
                    :disabled="!hasActivity"
                    :class="[
                        'rounded-full shadow-none disabled:cursor-default',
                        hidden.has(item.key)
                            ? 'border-dashed text-muted-foreground opacity-60'
                            : 'text-foreground',
                    ]"
                    @click="toggle(item.key)"
                >
                    <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: hidden.has(item.key) ? '#cbd5e1' : color(item.tone) }"></span>
                    {{ item.label }}
                    <strong class="font-heading tabular-nums text-foreground">{{ item.total }}</strong>
                </Button>
                <span v-if="hasActivity && allSeries.length > 1" class="ms-auto hidden text-[11px] text-muted-foreground lg:inline">Cliquez une activité pour la masquer</span>
            </div>
        </header>

        <div v-if="hasActivity && dates.length" class="px-3 pb-3 pt-4 sm:px-5">
            <div class="overflow-x-auto">
                <svg
                    :class="['min-w-[620px] w-full', compact ? 'h-[300px]' : '']"
                    :viewBox="`0 0 ${chart.width} ${chart.height}`"
                    role="img"
                    aria-labelledby="activity-chart-title activity-chart-description"
                >
                    <title id="activity-chart-title">{{ viewMode === 'bars' ? 'Histogramme' : 'Courbes' }} de l’activité : {{ title }}</title>
                    <desc id="activity-chart-description">Chaque série présente le nombre quotidien de patients ou d’enregistrements autorisés.</desc>

                    <g v-for="tick in yTicks" :key="`tick-${tick}`">
                        <line
                            :x1="chart.left"
                            :x2="chart.width - chart.right"
                            :y1="yPosition(tick)"
                            :y2="yPosition(tick)"
                            class="stroke-border"
                            stroke-width="1"
                            stroke-dasharray="3 4"
                        />
                        <text
                            :x="chart.left - 12"
                            :y="yPosition(tick) + 4"
                            text-anchor="end"
                            class="fill-muted-foreground text-[11px]"
                        >{{ tick }}</text>
                    </g>

                    <g v-for="(label, index) in dateLabels" :key="dates[index]">
                        <text
                            v-if="isLabelVisible(index)"
                            :x="viewMode === 'bars' ? histogramLabelX(index) : xPosition(index)"
                            :y="chart.height - 9"
                            text-anchor="middle"
                            :class="['text-[11px] capitalize', index === dateLabels.length - 1 ? 'fill-foreground font-bold' : 'fill-muted-foreground']"
                        >{{ label }}</text>
                    </g>

                    <g v-if="viewMode === 'bars'">
                        <template v-for="(item, seriesIndex) in series" :key="item.key">
                            <rect
                                v-for="(value, dateIndex) in item.values"
                                :key="`${item.key}-${dates[dateIndex]}`"
                                :x="histogramX(dateIndex, seriesIndex) + 1"
                                :y="yPosition(value)"
                                :width="Math.max(1, histogramBarWidth - 2)"
                                :height="Math.max(0, yPosition(0) - yPosition(value))"
                                :fill="color(item.tone)"
                                rx="2.5"
                                class="opacity-90 transition-opacity hover:opacity-100"
                            >
                                <title>{{ item.label }} · {{ dateLabels[dateIndex] }} : {{ value }}</title>
                            </rect>
                        </template>
                    </g>

                    <g v-for="item in viewMode === 'lines' ? series : []" :key="item.key">
                        <polyline
                            :points="points(item)"
                            fill="none"
                            :stroke="color(item.tone)"
                            stroke-width="2.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                        />
                        <circle
                            v-for="(value, index) in item.values"
                            :key="`${item.key}-${dates[index]}`"
                            :cx="xPosition(index)"
                            :cy="yPosition(value)"
                            r="3.5"
                            :fill="color(item.tone)"
                            class="stroke-card"
                            stroke-width="2"
                            vector-effect="non-scaling-stroke"
                        >
                            <title>{{ item.label }} · {{ dateLabels[index] }} : {{ value }}</title>
                        </circle>
                    </g>
                </svg>
            </div>
        </div>

        <div v-else class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-muted text-muted-foreground ring-1 ring-inset ring-border"><LineChart class="h-5 w-5" /></span>
            <h3 class="text-sm font-bold text-foreground">{{ allSeries.length ? 'Aucune activité sur les 7 derniers jours' : 'Aucune série disponible' }}</h3>
            <p class="max-w-sm text-xs leading-5 text-muted-foreground">{{ allSeries.length ? 'La courbe apparaîtra dès le premier passage, patient ou encaissement enregistré.' : 'Aucune donnée compatible avec vos permissions n’est disponible pour cette période.' }}</p>
        </div>
    </Card>
</template>
