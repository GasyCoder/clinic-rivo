<script setup>
import { computed } from 'vue';

const props = defineProps({
    trend: {
        type: Object,
        default: () => ({ dates: [], series: [] }),
    },
});

const chart = {
    width: 820,
    height: 280,
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
const series = computed(() => props.trend?.series ?? []);
const plotWidth = chart.width - chart.left - chart.right;
const plotHeight = chart.height - chart.top - chart.bottom;

const dateLabels = computed(() => dates.value.map((date) => {
    const parsed = new Date(`${date}T12:00:00`);
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

const yPosition = (value) => chart.top + plotHeight - ((value / chartMaximum.value) * plotHeight);

const points = (item) => (item.values ?? [])
    .map((value, index) => `${xPosition(index)},${yPosition(value)}`)
    .join(' ');

const color = (tone) => colors[tone] ?? colors.navy;
</script>

<template>
    <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
        <header class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-start sm:justify-between dark:border-gray-900">
            <div>
                <h2 class="font-heading text-base font-bold text-slate-700 dark:text-white">Activité des 7 derniers jours</h2>
                <p class="mt-1 text-xs leading-5 text-slate-400">Volumes quotidiens visibles selon les permissions de votre compte.</p>
            </div>

            <div v-if="series.length" class="flex flex-wrap gap-x-5 gap-y-2">
                <div v-for="item in series" :key="item.key" class="flex items-center gap-2 text-xs">
                    <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: color(item.tone) }"></span>
                    <span class="text-slate-500 dark:text-slate-400">{{ item.label }}</span>
                    <strong class="font-heading text-slate-700 dark:text-white">{{ item.total }}</strong>
                </div>
            </div>
        </header>

        <div v-if="series.length && dates.length" class="px-3 pb-3 pt-5 sm:px-5">
            <div class="overflow-x-auto">
                <svg
                    class="min-w-[660px] w-full"
                    :viewBox="`0 0 ${chart.width} ${chart.height}`"
                    role="img"
                    aria-labelledby="activity-chart-title activity-chart-description"
                >
                    <title id="activity-chart-title">Courbe d’activité sur sept jours</title>
                    <desc id="activity-chart-description">Chaque ligne présente le nombre quotidien d’enregistrements autorisés.</desc>

                    <g v-for="tick in yTicks" :key="`tick-${tick}`">
                        <line
                            :x1="chart.left"
                            :x2="chart.width - chart.right"
                            :y1="yPosition(tick)"
                            :y2="yPosition(tick)"
                            class="stroke-gray-100 dark:stroke-gray-900"
                            stroke-width="1"
                        />
                        <text
                            :x="chart.left - 12"
                            :y="yPosition(tick) + 4"
                            text-anchor="end"
                            class="fill-slate-400 text-[11px]"
                        >{{ tick }}</text>
                    </g>

                    <g v-for="(label, index) in dateLabels" :key="dates[index]">
                        <text
                            :x="xPosition(index)"
                            :y="chart.height - 9"
                            text-anchor="middle"
                            class="fill-slate-400 text-[11px] capitalize"
                        >{{ label }}</text>
                    </g>

                    <g v-for="item in series" :key="item.key">
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
                            class="stroke-white dark:stroke-gray-950"
                            stroke-width="2"
                            vector-effect="non-scaling-stroke"
                        >
                            <title>{{ item.label }} · {{ dateLabels[index] }} : {{ value }}</title>
                        </circle>
                    </g>
                </svg>
            </div>
        </div>

        <div v-else class="flex min-h-[280px] items-center justify-center px-6 py-10 text-center">
            <div class="max-w-md">
                <h3 class="text-sm font-bold text-slate-600 dark:text-slate-300">Aucune série disponible</h3>
                <p class="mt-1 text-xs leading-5 text-slate-400">Aucune donnée compatible avec vos permissions n’est actuellement disponible pour cette période.</p>
            </div>
        </div>
    </section>
</template>
