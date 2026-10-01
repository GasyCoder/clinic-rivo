<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    Building2,
    CalendarDays,
    CircleDollarSign,
    Clock3,
    Construction,
    FileText,
    Hash,
    HeartPulse,
    Info,
    Landmark,
    RefreshCw,
    Server,
    Settings2,
    ShieldCheck,
    UserPlus,
    Users,
    Wallet,
    Baby,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { usePermissions } from '@/composables/usePermissions';
import { lucideIcon } from '@/lib/icons';
import { formatMoney } from '@/utilities/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clinic: { type: Object, required: true },
    selectedModule: { type: Object, required: true },
    siteReport: { type: Object, required: true },
    days: { type: Number, default: 30 },
});

const { can } = usePermissions();

const sections = computed(() => props.siteReport.data?.sections ?? {});
const section = (key) => sections.value[key];
const readable = (key) => section(key)?.available === true;
const number = (value) => value === null || value === undefined
    ? '—'
    : new Intl.NumberFormat('fr-FR').format(value);
const money = (value) => value === null || value === undefined
    ? '—'
    : formatMoney(Math.round(value));
const destination = (key) => readable('clinical')
    ? (section('clinical').destinations ?? []).find((row) => row.key === key)
    : null;
const totalWaiting = () => readable('clinical')
    ? (section('clinical').destinations ?? []).reduce((total, row) => total + Number(row.waiting ?? 0), 0)
    : null;

const makeMetric = (key, label, value, hint, icon, options = {}) => ({
    key,
    label,
    value,
    hint,
    icon,
    money: options.money ?? false,
});

const unavailableMetric = (key, label, sectionKey, icon) => makeMetric(
    key,
    label,
    null,
    section(sectionKey)?.reason ?? 'Cette donnée n’est pas autorisée par le site.',
    icon,
);

const activityCards = () => {
    if (! readable('activity')) {
        return [
            unavailableMetric('episodes', 'Passages aujourd’hui', 'activity', Activity),
            unavailableMetric('emergencies', 'Urgences aujourd’hui', 'activity', HeartPulse),
            unavailableMetric('new-patients', 'Nouveaux patients', 'activity', UserPlus),
            unavailableMetric('open-episodes', 'Passages ouverts', 'activity', Clock3),
        ];
    }

    const activity = section('activity');

    return [
        makeMetric('episodes', 'Passages aujourd’hui', activity.today?.episodes, 'Admissions enregistrées par le site', Activity),
        makeMetric('emergencies', 'Urgences aujourd’hui', activity.today?.emergencies, 'Passages classés en urgence', HeartPulse),
        makeMetric('new-patients', 'Nouveaux patients', activity.today?.new_patients, 'Dossiers créés aujourd’hui', UserPlus),
        makeMetric('open-episodes', 'Passages ouverts', activity.open_episodes, 'Épisodes encore actifs', Clock3),
    ];
};

const clinicalCards = (moduleCode, label) => {
    if (! readable('clinical')) {
        return [
            unavailableMetric('waiting', `${label} · file active`, 'clinical', Clock3),
            unavailableMetric('pending', 'En attente', 'clinical', CalendarDays),
            unavailableMetric('in-progress', 'En cours', 'clinical', Activity),
            unavailableMetric('completed', 'Terminées', 'clinical', ShieldCheck),
        ];
    }

    const row = destination(moduleCode) ?? { waiting: 0, pending: 0, in_progress: 0, completed: 0 };

    return [
        makeMetric('waiting', `${label} · file active`, row.waiting, 'En attente ou en cours', Clock3),
        makeMetric('pending', 'En attente', row.pending, 'Orientations reçues', CalendarDays),
        makeMetric('in-progress', 'En cours', row.in_progress, 'Prises en charge actives', Activity),
        makeMetric('completed', 'Terminées', row.completed, 'Orientations achevées', ShieldCheck),
    ];
};

const financeCards = () => {
    if (! readable('finance')) {
        return [
            unavailableMetric('invoiced', 'Facturé sur la période', 'finance', FileText),
            unavailableMetric('collected', 'Encaissé sur la période', 'finance', Wallet),
            unavailableMetric('outstanding', 'Reste à payer', 'finance', CircleDollarSign),
            unavailableMetric('invoices', 'Factures émises', 'finance', FileText),
        ];
    }

    const totals = section('finance').totals ?? {};

    return [
        makeMetric('invoiced', 'Facturé sur la période', totals.invoiced, `${props.days} derniers jours`, FileText, { money: true }),
        makeMetric('collected', 'Encaissé sur la période', totals.collected, 'Uniquement par Réception / Caisse', Wallet, { money: true }),
        makeMetric('outstanding', 'Reste à payer', totals.outstanding, 'Factures non annulées', CircleDollarSign, { money: true }),
        makeMetric('invoices', 'Factures émises', totals.invoices, `${props.days} derniers jours`, FileText),
    ];
};

const reportCards = () => [
    readable('activity')
        ? makeMetric('activity', 'Passages aujourd’hui', section('activity').today?.episodes, 'Rapport d’activité', Activity)
        : unavailableMetric('activity', 'Rapport d’activité', 'activity', Activity),
    readable('finance')
        ? makeMetric('finance', 'Encaissé sur la période', section('finance').totals?.collected, 'Rapport financier', Wallet, { money: true })
        : unavailableMetric('finance', 'Rapport financier', 'finance', Wallet),
    readable('pharmacy')
        ? makeMetric('pharmacy', 'Lots à surveiller', Number(section('pharmacy').expiring_soon ?? 0) + Number(section('pharmacy').expired ?? 0), 'Péremptions proches ou dépassées', HeartPulse)
        : unavailableMetric('pharmacy', 'Rapport pharmacie', 'pharmacy', HeartPulse),
    readable('people')
        ? makeMetric('people', 'Employés', section('people').employees, `${number(section('people').accounts)} compte(s) actif(s)`, Users)
        : unavailableMetric('people', 'Rapport administratif', 'people', Users),
];

const cards = computed(() => {
    switch (props.selectedModule.code) {
        case 'OVERVIEW': {
            if (! readable('activity')) return activityCards();
            const activity = section('activity');

            return [
                makeMetric('episodes', 'Passages aujourd’hui', activity.today?.episodes, 'Activité enregistrée', Activity),
                makeMetric('open', 'Passages ouverts', activity.open_episodes, 'Épisodes encore actifs', Clock3),
                makeMetric('patients', 'Patients enregistrés', activity.total_patients, 'Dossiers du site', Users),
                readable('clinical')
                    ? makeMetric('services', 'File active des services', totalWaiting(), 'Orientations en attente ou en cours', HeartPulse)
                    : unavailableMetric('services', 'File active des services', 'clinical', HeartPulse),
            ];
        }
        case 'RECEPTION':
            return activityCards();
        case 'CASH':
            return financeCards();
        case 'PATIENTS': {
            if (! readable('activity')) return activityCards();
            const activity = section('activity');

            return [
                makeMetric('patients', 'Patients enregistrés', activity.total_patients, 'Dossiers administratifs du site', Users),
                makeMetric('new-patients', 'Nouveaux patients', activity.today?.new_patients, 'Créés aujourd’hui', UserPlus),
                makeMetric('episodes', 'Passages aujourd’hui', activity.today?.episodes, 'Épisodes démarrés aujourd’hui', Activity),
                makeMetric('open-episodes', 'Passages ouverts', activity.open_episodes, 'Épisodes encore actifs', Clock3),
            ];
        }
        case 'MEDICINE':
            return clinicalCards('MEDICINE', 'Médecine');
        case 'CARE':
            return clinicalCards('CARE', 'Soins');
        case 'SURGERY':
            return clinicalCards('SURGERY', 'Chirurgie');
        case 'REPORTS':
            return reportCards();
        default:
            return [];
    }
});

const relevantSections = computed(() => ({
    OVERVIEW: ['activity', 'clinical'],
    RECEPTION: ['activity', 'clinical'],
    CASH: ['finance'],
    PATIENTS: ['activity'],
    MEDICINE: ['clinical'],
    CARE: ['clinical'],
    SURGERY: ['clinical'],
    REPORTS: ['activity', 'finance', 'clinical', 'pharmacy', 'people'],
}[props.selectedModule.code] ?? []));

const unavailableSections = computed(() => relevantSections.value
    .map((key) => ({ key, section: section(key) }))
    .filter((entry) => entry.section && entry.section.available === false));

const generatedAt = computed(() => {
    const value = props.siteReport.data?.generated_at;

    return value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : null;
});

const moduleHref = (module) => `/super-admin/sites/${props.clinic.code}?module=${module.code}&days=${props.days}`;
const periodHref = (period) => `/super-admin/sites/${props.clinic.code}?module=${props.selectedModule.code}&days=${period}`;
const refresh = () => router.reload({ only: ['siteReport'], preserveScroll: true });
</script>

<template>
    <Head :title="`${clinic.name} — ${selectedModule.label}`" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5 pb-8">
        <Card class="overflow-hidden border-primary/15">
            <header class="relative flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-primary via-sky-500 to-emerald-500" />
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Site opérationnel</p>
                        <h1 class="font-heading text-2xl font-bold text-foreground">{{ clinic.name }}</h1>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="siteReport.ok ? 'success' : 'warning'">
                        <span :class="['h-1.5 w-1.5 rounded-full', siteReport.ok ? 'bg-emerald-500' : 'bg-amber-500']" />
                        {{ siteReport.ok ? 'API disponible' : siteReport.status === 'UNCONFIGURED' ? 'API à configurer' : 'API indisponible' }}
                    </Badge>
                    <Button type="button" variant="outline" size="sm" @click="refresh"><RefreshCw class="h-4 w-4" />Actualiser</Button>
                </div>
            </header>
        </Card>

        <nav class="flex gap-2 overflow-x-auto rounded-lg border border-border bg-card p-2" aria-label="Modules du site">
            <Link v-for="module in clinic.modules" :key="module.code" :href="moduleHref(module)" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold transition-colors', selectedModule.code === module.code ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground']">
                <component class="h-4 w-4" :is="lucideIcon(module.icon)" />{{ module.label }}
            </Link>
        </nav>

        <Card v-if="selectedModule.code === 'PATIENTS' && can('settings.view')" class="p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Settings2 class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-sm font-bold text-foreground">Configuration des patients</h2>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">Ces règles appartiennent à Patients et s’appliquent uniquement aux nouveaux dossiers de {{ clinic.name }}.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button :as="Link" :href="`/super-admin/sites/${clinic.code}/patients/settings/numerotation`" variant="outline" size="sm"><Hash class="h-4 w-4" />Numérotation</Button>
                    <Button :as="Link" :href="`/super-admin/sites/${clinic.code}/patients/settings/ages`" variant="outline" size="sm"><Baby class="h-4 w-4" />Âges des patients</Button>
                </div>
            </div>
        </Card>

        <Card v-if="selectedModule.code === 'OVERVIEW' && can('settings.view')" class="p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Settings2 class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-sm font-bold text-foreground">Configuration de l’établissement</h2>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">Informations légales et disponibilité opérationnelle de {{ clinic.name }}.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button :as="Link" :href="`/super-admin/sites/${clinic.code}/organization/settings/legal`" variant="outline" size="sm"><Landmark class="h-4 w-4" />Identité légale</Button>
                    <Button :as="Link" :href="`/super-admin/sites/${clinic.code}/organization/settings/maintenance`" variant="outline" size="sm"><Construction class="h-4 w-4" />Maintenance</Button>
                </div>
            </div>
        </Card>

        <Card v-if="! siteReport.ok" class="border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900 dark:bg-amber-950/20" role="alert">
            <div class="flex items-start gap-3">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" />
                <div>
                    <h2 class="text-sm font-bold text-amber-950 dark:text-amber-100">Les données de {{ clinic.name }} ne sont pas disponibles</h2>
                    <p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-200">{{ siteReport.message }}</p>
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">Aucun zéro n’est affiché tant que le site n’a pas répondu.</p>
                </div>
            </div>
        </Card>

        <section v-else class="space-y-4">
            <Card class="overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-border px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg border border-border text-muted-foreground"><component class="h-4.5 w-4.5" :is="lucideIcon(selectedModule.icon)" /></span>
                        <div>
                            <h2 class="text-sm font-bold text-foreground">{{ selectedModule.label }}</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">{{ selectedModule.description }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="mr-1 inline-flex items-center gap-2 text-xs text-muted-foreground"><Server class="h-4 w-4" />Données API · {{ generatedAt || 'à l’instant' }}</span>
                        <Button v-for="period in [7, 30, 90]" :key="period" :as="Link" :href="periodHref(period)" :variant="days === period ? 'default' : 'outline'" size="sm">{{ period }} j</Button>
                    </div>
                </div>

                <div class="grid gap-px bg-muted sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="metric in cards" :key="metric.key" class="min-h-36 bg-card p-5">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-muted text-muted-foreground"><component :is="metric.icon" class="h-4.5 w-4.5" /></span>
                        <p class="mt-4 font-heading text-2xl font-bold tabular-nums text-foreground">{{ metric.money ? money(metric.value) : number(metric.value) }}</p>
                        <h3 class="mt-1 text-sm font-bold text-foreground">{{ metric.label }}</h3>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">{{ metric.hint }}</p>
                    </article>
                </div>

                <div v-if="selectedModule.notice" class="flex items-start gap-3 border-t border-border bg-muted/70 px-5 py-4"><Info class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" /><p class="text-xs leading-5 text-muted-foreground">{{ selectedModule.notice }}</p></div>
                <div v-else class="flex items-start gap-3 border-t border-border px-5 py-4"><Info class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" /><p class="text-xs leading-5 text-muted-foreground">Lecture via l’API sécurisée de {{ clinic.name }}. Les actions métier restent soumises aux permissions locales et à l’audit du site.</p></div>
            </Card>

            <Card v-if="unavailableSections.length" class="border-amber-200 p-4 dark:border-amber-900">
                <p class="flex items-start gap-2 text-xs text-muted-foreground"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />Certaines sections sont masquées par les permissions du site : {{ unavailableSections.map((entry) => entry.section.reason).join(' ') }}</p>
            </Card>
        </section>
    </div>
</template>
