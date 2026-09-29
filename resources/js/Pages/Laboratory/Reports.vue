<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityTrendChart from '@/Components/Dashboard/ActivityTrendChart.vue';
import BarChart from '@/Components/Charts/BarChart.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import {
    AlertTriangle, ArrowLeft, BadgeCheck, BarChart3, Building2, Download, FlaskConical, Hourglass, Inbox, RotateCcw, TestTubes, Timer,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDate, localToday, toLocalDateInput } from '@/utilities/date';
import { labUrl } from '@/utilities/labUrl';

defineOptions({ layout: AppLayout });

/**
 * ADR-214 — les rapports du Laboratoire (CDC §14) : l'activité d'une période,
 * les délais, la répartition par discipline et par origine, et ce qui attend
 * encore. Chaque chiffre se lit sur une date réellement enregistrée ; un délai
 * que rien n'a permis de mesurer s'écrit « — », jamais 0 (ADR-102). Aucun
 * montant : le Laboratoire ne compte pas l'argent (ADR-014).
 */
const props = defineProps({
    report: { type: Object, required: true },
    canExport: { type: Boolean, default: false },
});

const from = ref(props.report.period.from);
const to = ref(props.report.period.to);
const visit = (range) => router.get(labUrl('/laboratory/rapports'), { du: range.from, au: range.to }, { preserveScroll: true, preserveState: true, replace: true });
const apply = () => visit({ from: from.value, to: to.value });

const daysBack = (days) => {
    const end = new Date(`${localToday()}T12:00:00`);
    const start = new Date(end);
    start.setDate(start.getDate() - (days - 1));
    return { from: toLocalDateInput(start), to: toLocalDateInput(end) };
};
const PRESETS = [
    { key: '7', label: '7 jours', range: () => daysBack(7) },
    { key: '30', label: '30 jours', range: () => daysBack(30) },
    { key: '90', label: '90 jours', range: () => daysBack(90) },
    { key: 'year', label: 'Cette année', range: () => ({ from: `${localToday().slice(0, 4)}-01-01`, to: localToday() }) },
];
const pick = (preset) => {
    const range = preset.range();
    from.value = range.from;
    to.value = range.to;
    visit(range);
};
const isPreset = (preset) => {
    const range = preset.range();
    return range.from === props.report.period.from && range.to === props.report.period.to;
};

const exportUrl = computed(() => labUrl(`/laboratory/rapports/export?du=${props.report.period.from}&au=${props.report.period.to}`));
const totals = computed(() => props.report.totals ?? {});
const number = (value) => new Intl.NumberFormat('fr-FR').format(value ?? 0);
const hours = (value) => {
    if (value === null || value === undefined) return '—';
    if (value < 1) return `${Math.round(value * 60)} min`;
    if (value < 48) return `${String(value).replace('.', ',')} h`;
    return `${String(Math.round((value / 24) * 10) / 10).replace('.', ',')} j`;
};

const kpis = computed(() => [
    { key: 'requests', label: 'Demandes', value: totals.value.requests, hint: `${number(totals.value.requested_items)} analyse(s) demandée(s)`, icon: TestTubes, tone: 'primary' },
    { key: 'resulted', label: 'Analyses rendues', value: totals.value.resulted, hint: `${number(totals.value.received)} demande(s) reçue(s)`, icon: FlaskConical, tone: 'sky' },
    { key: 'validated', label: 'Envoyées', value: totals.value.validated, hint: 'au médecin', icon: BadgeCheck, tone: 'emerald' },
    { key: 'critical', label: 'Résultats critiques', value: totals.value.critical, hint: `${number(totals.value.pathological)} pathologique(s)`, icon: AlertTriangle, tone: 'red' },
    { key: 'returned', label: 'Renvoyées à refaire', value: totals.value.returned, hint: 'avec un motif', icon: RotateCcw, tone: 'amber' },
    { key: 'sent_out', label: 'Confiées à l’extérieur', value: totals.value.sent_out, hint: 'laboratoires partenaires', icon: Building2, tone: 'neutral' },
]);
const KPI_TONES = {
    primary: 'bg-primary/10 text-primary',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300',
    red: 'bg-red-50 text-destructive dark:bg-red-950/40',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    neutral: 'bg-muted text-muted-foreground',
};

const delays = computed(() => [
    { key: 'request_to_result', label: 'Demande → résultat', data: props.report.delays?.request_to_result },
    { key: 'reception_to_result', label: 'Réception → résultat', data: props.report.delays?.reception_to_result },
    { key: 'result_to_validation', label: 'Résultat → envoi', data: props.report.delays?.result_to_validation },
]);

const backlog = computed(() => [
    { key: 'to_receive', label: 'À réceptionner', value: totals.value.backlog_to_receive, href: labUrl('/laboratory?view=to_receive'), icon: Inbox },
    { key: 'open', label: 'À analyser', value: totals.value.backlog_open, href: labUrl('/laboratory?view=to_do'), icon: Hourglass },
    { key: 'to_validate', label: 'À envoyer', value: totals.value.backlog_to_validate, href: labUrl('/laboratory?view=to_validate'), icon: BadgeCheck },
]);

const periodLabel = computed(() => `Du ${formatDate(props.report.period.from)} au ${formatDate(props.report.period.to)} · ${props.report.period.days} jour(s)`);
</script>

<template>
    <Head title="Rapports du laboratoire" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <Button :as="Link" :href="labUrl('/laboratory')" variant="ghost" size="sm"><ArrowLeft class="h-4 w-4" /> File du laboratoire</Button>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><BarChart3 class="h-6 w-6" /></span>
                <div>
                    <h1 class="font-heading text-2xl font-bold -tracking-snug text-foreground">Rapports du laboratoire</h1>
                    <p class="mt-1 text-sm text-muted-foreground">{{ periodLabel }}</p>
                </div>
            </div>
            <Button v-if="canExport" as="a" :href="exportUrl" variant="outline" size="sm"><Download class="h-4 w-4" /> Exporter en Excel</Button>
        </header>

        <Card class="flex flex-wrap items-end gap-3 p-4">
            <div class="inline-flex flex-wrap rounded-lg bg-muted p-1" role="group" aria-label="Période">
                <Button
                    v-for="preset in PRESETS"
                    :key="preset.key"
                    type="button"
                    size="xs"
                    :variant="isPreset(preset) ? 'secondary' : 'ghost'"
                    :aria-pressed="isPreset(preset)"
                    class="shadow-none"
                    @click="pick(preset)"
                >{{ preset.label }}</Button>
            </div>
            <label class="grid gap-1 text-xs font-semibold text-muted-foreground">Du <DatePicker v-model="from" :max="to" class="w-40" /></label>
            <label class="grid gap-1 text-xs font-semibold text-muted-foreground">Au <DatePicker v-model="to" :min="from" :max="localToday()" class="w-40" /></label>
            <Button type="button" size="sm" :disabled="!from || !to" @click="apply">Afficher</Button>
        </Card>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <Card v-for="kpi in kpis" :key="kpi.key" class="flex items-start gap-3 p-4">
                <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', KPI_TONES[kpi.tone])"><component :is="kpi.icon" class="h-4.5 w-4.5" /></span>
                <div class="min-w-0">
                    <p class="font-heading text-2xl font-bold tabular-nums text-foreground">{{ number(kpi.value) }}</p>
                    <p class="text-xs font-semibold text-foreground">{{ kpi.label }}</p>
                    <p class="text-[11px] text-muted-foreground">{{ kpi.hint }}</p>
                </div>
            </Card>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <ActivityTrendChart :trend="report.trend" title="Activité par jour" description="Analyses demandées, rendues et envoyées, jour par jour." empty-title="Aucune activité sur la période" empty-description="La courbe apparaîtra dès la première analyse demandée." />

            <div class="space-y-4">
                <Card class="p-4">
                    <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-foreground"><Timer class="h-4 w-4 text-primary" /> Délais médians</h2>
                    <ul class="space-y-2.5">
                        <li v-for="delay in delays" :key="delay.key" class="flex items-baseline justify-between gap-3">
                            <span class="text-sm text-foreground">{{ delay.label }}</span>
                            <span class="text-right">
                                <strong class="font-heading text-lg tabular-nums text-foreground">{{ hours(delay.data?.median_hours) }}</strong>
                                <span class="block text-[11px] text-muted-foreground">
                                    <template v-if="delay.data?.count">moyenne {{ hours(delay.data.average_hours) }} · {{ number(delay.data.count) }} mesure(s)</template>
                                    <template v-else>rien à mesurer</template>
                                </span>
                            </span>
                        </li>
                    </ul>
                </Card>
                <Card class="p-4">
                    <h2 class="mb-1 flex items-center gap-2 text-sm font-bold text-foreground"><Hourglass class="h-4 w-4 text-primary" /> En attente maintenant</h2>
                    <p class="mb-3 text-[11px] text-muted-foreground">Ce qui attend à cet instant, quelle que soit la période.</p>
                    <ul class="grid grid-cols-3 gap-2">
                        <li v-for="row in backlog" :key="row.key">
                            <Link :href="row.href" class="block rounded-lg border border-border p-2.5 text-center transition-colors hover:border-primary/40 hover:bg-muted/40">
                                <component :is="row.icon" class="mx-auto h-4 w-4 text-muted-foreground" />
                                <strong :class="cn('mt-1 block font-heading text-xl tabular-nums', row.value ? 'text-foreground' : 'text-muted-foreground')">{{ number(row.value) }}</strong>
                                <span class="text-[11px] text-muted-foreground">{{ row.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </Card>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="p-4">
                <h2 class="mb-3 text-sm font-bold text-foreground">Analyses rendues par discipline</h2>
                <BarChart :items="report.disciplines" empty-label="Aucune analyse rendue sur la période." />
            </Card>
            <Card class="p-4">
                <h2 class="mb-3 text-sm font-bold text-foreground">Analyses les plus demandées</h2>
                <BarChart :items="report.top_analyses" empty-label="Aucune analyse demandée sur la période." />
            </Card>
            <Card class="p-4">
                <h2 class="mb-3 text-sm font-bold text-foreground">Demandes par origine</h2>
                <BarChart :items="report.origins" empty-label="Aucune demande sur la période." />
            </Card>
        </div>
    </div>
</template>
