<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Banknote,
    Building2,
    CalendarClock,
    Layers,
    Pill,
    Receipt,
    SlidersHorizontal,
    ShieldCheck,
    TriangleAlert,
    UserPlus,
    Users,
    Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ActivityTrendChart from '@/Components/Dashboard/ActivityTrendChart.vue';
import BarChart from '@/Components/Charts/BarChart.vue';
import DonutChart from '@/Components/Charts/DonutChart.vue';
import { cn } from '@/lib/cn';
import { isOfflineAlertAcknowledged, rememberOfflineAlert } from '@/utilities/offlineAlert';

defineOptions({ layout: AppLayout });

/**
 * Le tableau de bord central (ADR-102).
 *
 * Il affichait « — » partout : aucun endpoint ne comptait quoi que ce soit.
 * Chaque chiffre vient désormais du rapport que chaque site sert par son
 * API — `admin.rivo.mg` n'ouvre jamais une connexion vers une base clinique
 * (ADR-004, ADR-027).
 *
 * La règle d'affichage tient en une phrase : **une donnée absente n'est pas
 * un zéro**. Site injoignable, permission manquante, module pas encore
 * construit — l'écran l'écrit, et laisse la place vide plutôt que d'annoncer
 * « aucune recette » là où il faut lire « je n'ai pas pu compter » (ADR-048).
 */
const props = defineProps({
    sites: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
    reports: { type: Array, default: () => [] },
    days: { type: Number, default: 30 },
});

const page = usePage();

/** « Tous les sites » d'abord : c'est la question que se pose le portail. */
const scope = ref('ALL');
const period = ref(String(props.days));

watch(period, (value) => {
    if (Number(value) === props.days) return;

    router.get('/', { days: Number(value) }, { preserveState: true, preserveScroll: true, replace: true });
});

const windowOptions = [
    { value: '7', label: '7 derniers jours' },
    { value: '30', label: '30 derniers jours' },
    { value: '90', label: '90 derniers jours' },
];

const scopeOptions = computed(() => [
    { value: 'ALL', label: 'Tous les sites' },
    ...props.reports.map((report) => ({
        value: report.site?.code,
        label: report.ok ? report.site?.name : `${report.site?.name} — injoignable`,
    })),
]);

const online = computed(() => props.reports.filter((report) => report.ok));
const offline = computed(() => props.reports.filter((report) => ! report.ok));
const reportForSite = (code) => props.reports.find((report) => report.site?.code === code);
const offlineAlertKey = computed(() => `rivo:super-admin:offline-alert:${page.props.auth?.user?.uuid ?? page.props.auth?.user?.id ?? 'unknown'}`);
const offlineAcknowledged = ref(false);
const offlineAlertReady = ref(false);
const alertStorage = () => {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        return null;
    }
};

onMounted(() => {
    offlineAcknowledged.value = isOfflineAlertAcknowledged(alertStorage(), offlineAlertKey.value);
    offlineAlertReady.value = true;
});

const acknowledgeOfflineAlert = () => {
    offlineAcknowledged.value = true;
    rememberOfflineAlert(alertStorage(), offlineAlertKey.value);
};

/** Les rapports retenus par le filtre de site, en ligne uniquement. */
const selected = computed(() => (scope.value === 'ALL'
    ? online.value
    : online.value.filter((report) => report.site?.code === scope.value)));

const sections = (key) => selected.value
    .map((report) => ({ site: report.site, section: report.data?.sections?.[key] }))
    .filter((entry) => entry.section);

const readable = (key) => sections(key).filter((entry) => entry.section.available);

/**
 * Une section visible sur un site et refusée sur un autre doit se voir : le
 * total serait sinon celui d'un périmètre que personne n'a choisi.
 */
const blocked = (key) => sections(key).filter((entry) => ! entry.section.available);

const sum = (key, pick) => readable(key).reduce((total, entry) => {
    const value = pick(entry.section);

    return value === null || value === undefined ? total : total + Number(value);
}, 0);

/** `null` quand aucun site lisible ne porte la valeur : « — », jamais « 0 ». */
const consolidated = (key, pick) => (readable(key).some((entry) => {
    const value = pick(entry.section);

    return value !== null && value !== undefined;
})
    ? sum(key, pick)
    : null);

const number = (value) => (value === null || value === undefined
    ? '—'
    : new Intl.NumberFormat('fr-FR').format(value));

const money = (value) => (value === null || value === undefined
    ? '—'
    : `${new Intl.NumberFormat('fr-FR').format(Math.round(value))} Ar`);

/* ------------------------------------------------------------------ */
/* Compteurs de tête                                                   */
/* ------------------------------------------------------------------ */

const counters = computed(() => [
    {
        key: 'sites',
        label: 'Sites connectés',
        value: `${online.value.length} / ${props.reports.length}`,
        hint: offline.value.length ? `${offline.value.length} injoignable(s)` : 'Toutes les API répondent',
        icon: Building2,
        tone: offline.value.length ? 'amber' : 'emerald',
    },
    {
        key: 'episodes',
        label: 'Passages aujourd’hui',
        value: number(consolidated('activity', (s) => s.today?.episodes)),
        hint: `${number(consolidated('activity', (s) => s.open_episodes))} passage(s) encore ouverts`,
        icon: Activity,
        tone: 'primary',
    },
    {
        key: 'collected',
        label: 'Encaissé sur la période',
        value: money(consolidated('finance', (s) => s.totals?.collected)),
        hint: `${number(consolidated('finance', (s) => s.totals?.invoices))} facture(s) émise(s)`,
        icon: Wallet,
        tone: 'emerald',
    },
    {
        key: 'outstanding',
        label: 'Reste à payer',
        value: money(consolidated('finance', (s) => s.totals?.outstanding)),
        hint: 'Soldes des factures non annulées',
        icon: Receipt,
        tone: 'amber',
    },
]);

const TONES = {
    primary: { icon: 'bg-primary/10 text-primary', border: 'border-primary/15' },
    emerald: { icon: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300', border: 'border-emerald-200/70 dark:border-emerald-900/70' },
    amber: { icon: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300', border: 'border-amber-200/70 dark:border-amber-900/70' },
};

/* ------------------------------------------------------------------ */
/* Courbe d'activité — les sites additionnés jour par jour             */
/* ------------------------------------------------------------------ */

/**
 * Les séries des sites retenus sont additionnées date à date. Toutes
 * partagent la même fenêtre — le serveur la borne — donc les index
 * correspondent ; une série absente d'un site ne décale pas les autres.
 */
const trend = computed(() => {
    const entries = readable('activity').map((entry) => entry.section.trend).filter(Boolean);

    if (! entries.length) return { dates: [], series: [] };

    const dates = entries[0].dates ?? [];
    const merged = new Map();

    for (const source of entries) {
        for (const serie of source.series ?? []) {
            const existing = merged.get(serie.key);

            if (! existing) {
                merged.set(serie.key, { ...serie, values: [...serie.values] });
                continue;
            }

            existing.values = existing.values.map((value, index) => value + (serie.values[index] ?? 0));
            existing.total += serie.total;
        }
    }

    return { dates, series: [...merged.values()] };
});

const trendTitle = computed(() => (scope.value === 'ALL'
    ? `Activité des ${props.days} derniers jours · ${online.value.length} site(s)`
    : `Activité des ${props.days} derniers jours · ${selected.value[0]?.site?.name ?? ''}`));

/* ------------------------------------------------------------------ */
/* Répartitions                                                        */
/* ------------------------------------------------------------------ */

/** Les modes de paiement, additionnés par libellé entre les sites. */
const paymentMethods = computed(() => {
    const merged = new Map();

    for (const entry of readable('finance')) {
        for (const row of entry.section.by_method ?? []) {
            const current = merged.get(row.label) ?? { key: row.label, label: row.label, value: 0 };
            current.value += Number(row.amount ?? 0);
            merged.set(row.label, current);
        }
    }

    return [...merged.values()].sort((left, right) => right.value - left.value);
});

const queues = computed(() => {
    const merged = new Map();

    for (const entry of readable('clinical')) {
        for (const row of entry.section.destinations ?? []) {
            const current = merged.get(row.key) ?? { key: row.key, label: row.label, value: 0, completed: 0 };
            current.value += row.waiting;
            current.completed += row.completed;
            merged.set(row.key, current);
        }
    }

    return [...merged.values()]
        .map((row) => ({ ...row, hint: `${number(row.completed)} terminée(s)` }))
        .sort((left, right) => right.value - left.value);
});

const demographics = computed(() => {
    const merged = new Map();

    for (const entry of readable('activity')) {
        for (const segment of entry.section.demographics?.segments ?? []) {
            const current = merged.get(segment.key) ?? { key: segment.key, label: segment.label, value: 0 };
            current.value += segment.value;
            merged.set(segment.key, current);
        }
    }

    return [...merged.values()];
});

/* ------------------------------------------------------------------ */
/* Alertes et RH                                                       */
/* ------------------------------------------------------------------ */

const pharmacyAlerts = computed(() => readable('pharmacy').map((entry) => ({
    site: entry.site?.name,
    medicines: entry.section.medicines,
    expiring: entry.section.expiring_soon,
    expired: entry.section.expired,
})));

const people = computed(() => ({
    employees: consolidated('people', (s) => s.employees),
    leaves: consolidated('people', (s) => s.pending_leaves),
    accounts: consolidated('people', (s) => s.accounts),
}));

const debts = computed(() => ({
    count: consolidated('finance', (s) => s.debts?.count),
    amount: consolidated('finance', (s) => s.debts?.amount),
}));
</script>

<template>
    <Head title="Tableau de bord" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-6 pb-8">
        <Card class="overflow-hidden border-primary/15">
            <header class="relative flex flex-col gap-5 p-5 sm:p-6 xl:flex-row xl:items-center xl:justify-between">
                <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-primary via-sky-500 to-emerald-500" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                        <Badge variant="outline" class="border-primary/20 bg-primary/5 text-primary">Super Administration</Badge>
                    </div>
                    <h1 class="mt-3 font-heading text-2xl font-bold tracking-tight text-foreground sm:text-3xl">Pilotage des sites</h1>
                    <p class="mt-1 text-sm text-muted-foreground">{{ page.props.site.brand }} · Synthèse des sites accessibles par API</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end xl:justify-end">
                    <div class="min-w-0 sm:min-w-[190px]">
                        <label for="dashboard-scope" class="mb-1.5 block text-xs font-semibold text-muted-foreground">Périmètre</label>
                        <Select id="dashboard-scope" v-model="scope" :options="scopeOptions" :icon="Building2" aria-label="Périmètre" class="w-full" />
                    </div>
                    <div class="min-w-0 sm:min-w-[190px]">
                        <label for="dashboard-window" class="mb-1.5 block text-xs font-semibold text-muted-foreground">Période</label>
                        <Select id="dashboard-window" v-model="period" :options="windowOptions" :icon="SlidersHorizontal" aria-label="Fenêtre d’analyse" class="w-full" />
                    </div>
                    <Button :as="Link" href="/super-admin/workspaces/audit" variant="outline" class="w-full sm:w-auto">
                        <ShieldCheck class="h-4 w-4" />État des API
                    </Button>
                </div>
            </header>
        </Card>

        <!-- Ce que l'on ne peut pas lire se dit en tête, pas en note de bas
             de page : sans cela, un total partiel se lit comme un total. -->
        <Card v-if="offlineAlertReady && offline.length && ! offlineAcknowledged" class="flex flex-col gap-3 border-amber-200 bg-amber-50/60 p-4 sm:flex-row sm:items-start dark:border-amber-900 dark:bg-amber-950/20" role="alert">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <TriangleAlert class="mt-0.5 h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-300" />
                <div class="min-w-0 text-sm text-amber-900 dark:text-amber-100">
                    <p class="font-bold">{{ offline.length }} site(s) ne répondent pas — leurs chiffres ne sont pas dans ces totaux.</p>
                    <p v-for="report in offline" :key="report.site?.code" class="mt-0.5 text-xs">
                        <strong>{{ report.site?.name }}</strong> — {{ report.message || 'API injoignable' }}
                    </p>
                </div>
            </div>
            <Button type="button" variant="outline" size="sm" class="self-end border-amber-300 bg-card text-amber-900 hover:bg-amber-100 sm:self-start dark:border-amber-800 dark:text-amber-200 dark:hover:bg-amber-950/40" @click="acknowledgeOfflineAlert">
                J’ai compris
            </Button>
        </Card>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Chiffres clés">
            <Card v-for="counter in counters" :key="counter.key" :class="cn('min-w-0 p-5', TONES[counter.tone].border)">
                <div class="flex items-start justify-between gap-3">
                    <span class="text-xs font-semibold text-muted-foreground">{{ counter.label }}</span>
                    <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-xl', TONES[counter.tone].icon)">
                        <component :is="counter.icon" class="h-5 w-5" />
                    </span>
                </div>
                <p class="mt-3 font-heading text-2xl font-bold leading-tight tabular-nums text-foreground sm:text-[28px]">{{ counter.value }}</p>
                <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ counter.hint }}</p>
            </Card>
        </section>

        <!-- L'évolution dispose de sa propre ligne : une colonne de trois
             synthèses ne peut plus étirer le graphique et laisser du vide. -->
        <section class="min-w-0" aria-label="Évolution de l’activité">
            <ActivityTrendChart
                :trend="trend"
                :title="trendTitle"
                description="Passages, urgences et nouveaux patients, additionnés jour par jour sur les sites retenus."
                compact
            />
        </section>

        <section class="space-y-3" aria-labelledby="dashboard-breakdowns-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="dashboard-breakdowns-title" class="font-heading text-base font-bold text-foreground">Répartitions et comptes</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Une lecture détaillée du périmètre sélectionné.</p>
                </div>
            </div>
            <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card class="min-w-0 overflow-hidden">
                    <header class="border-b border-border px-5 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><Wallet class="h-4 w-4 text-primary" />Encaissements par mode</h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">Paiements enregistrés, hors annulés.</p>
                    </header>
                    <div class="p-5">
                        <DonutChart v-if="paymentMethods.length" :segments="paymentMethods" :format="money" unit="encaissé" compact-center />
                        <p v-else class="text-sm leading-6 text-muted-foreground">
                            <template v-if="blocked('finance').length">{{ blocked('finance')[0].section.reason }}</template>
                            <template v-else-if="! readable('finance').length">Données financières indisponibles pour ce périmètre.</template>
                            <template v-else>Aucun encaissement sur la période.</template>
                        </p>
                    </div>
                </Card>

                <Card class="min-w-0 overflow-hidden">
                    <header class="border-b border-border px-5 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><Users class="h-4 w-4 text-primary" />Patients enregistrés</h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">Dossiers permanents des sites retenus.</p>
                    </header>
                    <div class="p-5">
                        <DonutChart v-if="demographics.length" :segments="demographics" unit="patients" />
                        <p v-else class="text-sm leading-6 text-muted-foreground">
                            {{ readable('activity').length ? 'Aucun dossier patient enregistré sur les sites retenus.' : 'Données patient indisponibles pour ce périmètre.' }}
                        </p>
                    </div>
                </Card>

                <Card class="min-w-0 overflow-hidden md:col-span-2 xl:col-span-1">
                    <header class="border-b border-border px-5 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-bold text-foreground"><Banknote class="h-4 w-4 text-primary" />Comptes patients</h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">Factures et règlements du périmètre.</p>
                    </header>
                    <dl class="divide-y divide-border">
                        <div class="flex items-baseline justify-between gap-3 px-5 py-3.5">
                            <dt class="text-xs text-muted-foreground">Facturé sur la période</dt>
                            <dd class="text-sm font-bold tabular-nums text-foreground">{{ money(consolidated('finance', (s) => s.totals?.invoiced)) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 px-5 py-3.5">
                            <dt class="text-xs text-muted-foreground">Encaissé</dt>
                            <dd class="text-sm font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ money(consolidated('finance', (s) => s.totals?.collected)) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 px-5 py-3.5">
                            <dt class="text-xs text-muted-foreground">Reste à payer</dt>
                            <dd class="text-sm font-bold tabular-nums text-amber-700 dark:text-amber-300">{{ money(consolidated('finance', (s) => s.totals?.outstanding)) }}</dd>
                        </div>
                        <!-- ADR-090 : une créance n'est pas une facture
                             impayée, c'est une sortie prononcée sans solde. -->
                        <div class="flex items-baseline justify-between gap-3 px-5 py-3.5">
                            <dt class="text-xs text-muted-foreground">Créances validées</dt>
                            <dd class="text-sm font-bold tabular-nums text-foreground">
                                {{ money(debts.amount) }}<span v-if="debts.count !== null" class="ms-1 text-[11px] font-normal text-muted-foreground">({{ number(debts.count) }})</span>
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <Card class="overflow-hidden">
                <header class="border-b border-border px-5 py-4">
                    <h2 class="font-heading text-base font-bold text-foreground">Files des services</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Orientations en attente ou en cours. Une orientation retirée ne compte plus (ADR-079).</p>
                </header>
                <div class="p-5">
                    <BarChart :items="queues" :empty-label="readable('clinical').length ? 'Aucune orientation active sur les sites retenus.' : 'Données des services indisponibles pour ce périmètre.'" />
                    <p v-if="blocked('clinical').length" class="mt-4 flex items-start gap-2 text-xs text-muted-foreground">
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        {{ blocked('clinical').length }} site(s) n’ont pas pu être comptés : {{ blocked('clinical')[0].section.reason }}
                    </p>
                </div>
            </Card>

            <Card class="overflow-hidden">
                <header class="border-b border-border px-4 py-3">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><UserPlus class="h-4 w-4" />Personnel</h2>
                </header>
                <dl class="divide-y divide-border">
                    <div class="flex items-baseline justify-between gap-3 px-4 py-2.5">
                        <dt class="text-xs text-muted-foreground">Employés</dt>
                        <dd class="text-sm font-bold tabular-nums text-foreground">{{ number(people.employees) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 px-4 py-2.5">
                        <dt class="text-xs text-muted-foreground">Congés à décider</dt>
                        <dd class="text-sm font-bold tabular-nums text-foreground">{{ number(people.leaves) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 px-4 py-2.5">
                        <dt class="text-xs text-muted-foreground">Comptes actifs</dt>
                        <dd class="text-sm font-bold tabular-nums text-foreground">{{ number(people.accounts) }}</dd>
                    </div>
                </dl>
                <p v-if="blocked('people').length" class="border-t border-border px-4 py-2.5 text-[11px] text-muted-foreground">
                    {{ blocked('people')[0].section.reason }}
                </p>
            </Card>
        </div>

        <Card class="overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 class="flex items-center gap-2 font-heading text-base font-bold text-foreground"><Pill class="h-4 w-4" />Pharmacie — lots à surveiller</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Un lot périmé n’est jamais compté comme disponible (ADR-036).</p>
                </div>
                <Button :as="Link" href="/super-admin/stock" variant="outline" size="sm">
                    Stock médicaments<ArrowRight class="h-4 w-4" />
                </Button>
            </header>

            <div v-if="pharmacyAlerts.length" class="divide-y divide-border">
                <div v-for="alert in pharmacyAlerts" :key="alert.site" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                    <span class="flex items-center gap-2 text-sm font-semibold text-foreground"><Layers class="h-4 w-4 text-muted-foreground" />{{ alert.site }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{{ number(alert.medicines) }} médicaments</Badge>
                        <Badge v-if="alert.expiring" variant="warning"><CalendarClock class="h-3 w-3" />{{ number(alert.expiring) }} péremption(s) proche(s)</Badge>
                        <Badge v-if="alert.expired" variant="destructive">{{ number(alert.expired) }} lot(s) périmé(s)</Badge>
                        <Badge v-if="! alert.expiring && ! alert.expired" variant="success">Aucun lot à surveiller</Badge>
                    </div>
                </div>
            </div>
            <p v-else class="px-5 py-6 text-sm text-muted-foreground">
                <template v-if="blocked('pharmacy').length">{{ blocked('pharmacy')[0].section.reason }}</template>
                <template v-else>Aucun stock lisible sur les sites retenus.</template>
            </p>
        </Card>

        <section>
            <div class="mb-3 flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-foreground">Sites de la clinique</h2>
                    <p class="mt-1 text-xs text-muted-foreground">Chaque site conserve sa base, sa caisse et ses règles locales.</p>
                </div>
                <span class="text-xs text-muted-foreground">Données via API uniquement</span>
            </div>
            <div class="grid gap-4 lg:grid-cols-3">
                <Card v-for="site in sites" :key="site.code" class="p-5 transition-colors hover:border-primary/30">
                    <div class="flex items-start justify-between gap-4">
                        <span class="grid h-10 w-10 place-items-center rounded-lg bg-muted text-muted-foreground"><Building2 class="h-5 w-5" /></span>
                        <Badge :variant="reportForSite(site.code)?.ok ? 'success' : 'warning'">
                            {{ reportForSite(site.code)?.ok ? 'API disponible' : site.integration_status === 'CONFIGURED' ? 'API indisponible' : 'API à configurer' }}
                        </Badge>
                    </div>
                    <h3 class="mt-3 font-heading text-base font-bold text-foreground">{{ site.name }}</h3>
                    <p class="mt-1 text-xs text-muted-foreground">{{ site.modules?.length ?? 0 }} module(s) accessibles depuis le portail.</p>
                    <Button :as="Link" :href="`/super-admin/sites/${site.code}`" variant="outline" size="sm" class="mt-4">
                        Ouvrir<ArrowRight class="h-4 w-4" />
                    </Button>
                </Card>
            </div>
        </section>
    </div>
</template>
