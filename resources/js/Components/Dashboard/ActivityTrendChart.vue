<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    trend: {
        type: Object,
        default: () => ({ dates: [], series: [] }),
    },
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
    <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950">
        <header class="border-b border-gray-100 px-5 py-4 dark:border-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Activité des 7 derniers jours</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Volumes quotidiens visibles selon vos permissions.</p>
                </div>
                <span v-if="hasActivity && allSeries.length > 1" class="text-[11px] text-slate-400">Cliquez une légende pour l’afficher ou la masquer</span>
            </div>

            <div v-if="allSeries.length" class="mt-3 flex flex-wrap gap-1.5">
                <button
                    v-for="item in allSeries"
                    :key="item.key"
                    type="button"
                    :aria-pressed="!hidden.has(item.key)"
                    :disabled="!hasActivity"
                    :class="[
                        'inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs transition disabled:cursor-default',
                        hidden.has(item.key)
                            ? 'border-dashed border-gray-200 text-slate-400 dark:border-gray-800'
                            : 'border-gray-200 bg-white text-slate-600 hover:border-gray-300 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300',
                    ]"
                    @click="toggle(item.key)"
                >
                    <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: hidden.has(item.key) ? '#cbd5e1' : color(item.tone) }"></span>
                    {{ item.label }}
                    <strong class="font-heading tabular-nums text-slate-800 dark:text-white">{{ item.total }}</strong>
                </button>
            </div>
        </header>

        <div v-if="hasActivity && dates.length" class="px-3 pb-3 pt-4 sm:px-5">
            <div class="overflow-x-auto">
                <svg
                    class="min-w-[620px] w-full"
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
                            stroke-dasharray="3 4"
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
                            :class="['text-[11px] capitalize', index === dateLabels.length - 1 ? 'fill-slate-700 font-bold dark:fill-slate-200' : 'fill-slate-400']"
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

        <div v-else class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-gray-50 text-xl text-slate-400 ring-1 ring-inset ring-gray-100 dark:bg-gray-900 dark:ring-gray-800"><i class="ni ni-line-chart" /></span>
            <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ allSeries.length ? 'Aucune activité sur les 7 derniers jours' : 'Aucune série disponible' }}</h3>
            <p class="max-w-sm text-xs leading-5 text-slate-400">{{ allSeries.length ? 'La courbe apparaîtra dès le premier passage, patient ou encaissement enregistré.' : 'Aucune donnée compatible avec vos permissions n’est disponible pour cette période.' }}</p>
        </div>
    </section>
</template>
