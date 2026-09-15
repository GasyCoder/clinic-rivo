<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityTrendChart from '@/Components/Dashboard/ActivityTrendChart.vue';
import PharmacyHomePanel from '@/Components/Pharmacy/PharmacyHomePanel.vue';
import HrHomePanel from '@/Components/Administration/HrHomePanel.vue';
import { usePermissions } from '@/composables/usePermissions';
import {
    ArrowRight,
    Building2,
    CalendarDays,
    ChevronRight,
    CircleAlert,
    Clock,
    Plus,
    ShieldCheck,
} from 'lucide-vue-next';
import { lucideIcon } from '@/utilities/icons';
import { CLINIC_WORKSPACES, ROLE_FOCUS, WORKSPACE_GROUPS } from '@/utilities/clinicWorkspaces';

defineOptions({ layout: AppLayout });

const props = defineProps({
    overview: {
        type: Object,
        default: () => ({ generated_at: null, metrics: [], trend: { dates: [], series: [] } }),
    },
    // Present only for an account allowed into the Pharmacy (ADR-098).
    pharmacy: { type: Object, default: null },
    hr: { type: Object, default: null },
});

const page = usePage();
const { can } = usePermissions();
const user = computed(() => page.props.auth?.user ?? {});
const roleName = computed(() => user.value.role?.name ?? 'Profil opérationnel');
const userInitials = computed(() => user.value.name
    ?.split(/[\s-]+/)
    .filter(Boolean)
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase() ?? '');

const now = computed(() => (props.overview.generated_at ? new Date(props.overview.generated_at) : new Date()));

const greeting = computed(() => {
    const hour = now.value.getHours();

    return hour < 12 ? 'Bonjour' : hour < 18 ? 'Bon après-midi' : 'Bonsoir';
});

const generatedDate = computed(() => {
    const value = new Intl.DateTimeFormat('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(now.value);

    return value.charAt(0).toLocaleUpperCase('fr-FR') + value.slice(1);
});

const generatedTime = computed(() => new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(now.value));

/*
 * The page is the same frame for everyone, but its content is the account's
 * own: the role decides what comes first, the permissions decide what exists.
 * A nurse opens on the Soins queue, a pharmacist on the counter sale, and an
 * account with extra individual permissions simply sees those modules after
 * its role's own — never a module it cannot open.
 */
const roleCode = computed(() => user.value.role?.code ?? null);
const focus = computed(() => ROLE_FOCUS[roleCode.value] ?? { lead: null, primary: null, shortcuts: [], metrics: [] });

const workspaces = computed(() => CLINIC_WORKSPACES
    .filter((workspace) => can(workspace.permission))
    .map((workspace) => ({
        ...workspace,
        title: workspace.text,
        link: workspace.resolveLink ? workspace.resolveLink(can) : workspace.link,
    })));

const workspaceGroups = computed(() => Object.entries(WORKSPACE_GROUPS)
    .map(([key, title]) => ({ key, title, items: workspaces.value.filter((workspace) => workspace.group === key) }))
    .filter((group) => group.items.length));

/** Role's own action first; otherwise the most useful thing this account may do. */
const primaryAction = computed(() => {
    const own = focus.value.primary;

    if (own && can(own.permission)) return own;
    if (can('episodes.create')) return { label: 'Nouvelle prise en charge', link: '/reception/patients', icon: Plus };

    const workspace = workspaces.value[0];

    return workspace ? { label: `Ouvrir ${workspace.title}`, link: workspace.link, icon: ArrowRight } : null;
});

/**
 * Up to three shortcuts: the role's usual next stops it is allowed to open,
 * then — for a role without any — its first permitted workspaces. Never the
 * page the primary action already opens.
 */
const quickLinks = computed(() => {
    const primaryLink = primaryAction.value?.link;
    const byKey = (key) => workspaces.value.find((workspace) => workspace.key === key);
    const own = focus.value.shortcuts.map(byKey).filter(Boolean);
    const pool = own.length ? own : workspaces.value;

    return pool
        .filter((workspace) => workspace.link !== primaryLink && !primaryLink?.startsWith(`${workspace.link}/`))
        .slice(0, 3);
});

/*
 * Today's indicators, already filtered by permission on the server. The
 * role's own indicators lead, in the order that role reads them; whatever
 * else the account may see follows. An account whose role has no indicator
 * of its own gets a single, plainly named group.
 */
const metrics = computed(() => props.overview.metrics ?? []);
const focusMetrics = computed(() => focus.value.metrics
    .map((key) => metrics.value.find((metric) => metric.key === key))
    .filter(Boolean));
const otherMetrics = computed(() => metrics.value.filter((metric) => !focus.value.metrics.includes(metric.key)));

const gridFor = (count) => ({
    1: 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4',
    2: 'grid-cols-2',
    3: 'grid-cols-2 sm:grid-cols-3',
    4: 'grid-cols-2 xl:grid-cols-4',
    5: 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-5',
}[count] ?? 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-6');

const metricGroups = computed(() => [
    { key: 'focus', title: 'Votre activité', items: focusMetrics.value },
    { key: 'other', title: focusMetrics.value.length ? 'Autres indicateurs accessibles' : 'Indicateurs accessibles', items: otherMetrics.value },
].filter((group) => group.items.length).map((group) => ({ ...group, grid: gridFor(group.items.length) })));

const pendingOrientation = computed(() => metrics.value.find((metric) => metric.key === 'pending_orientation'));
const passagesToday = computed(() => metrics.value.find((metric) => metric.key === 'passages_today')?.value ?? null);

const toneClasses = {
    navy: { icon: 'bg-primary/10 text-primary ring-primary/20 ', value: 'text-primary ', bar: 'bg-primary' },
    ocean: { icon: 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-950/50 dark:text-sky-300 dark:ring-sky-900', value: 'text-sky-800 dark:text-sky-200', bar: 'bg-sky-500' },
    cyan: { icon: 'bg-cyan-50 text-cyan-700 ring-cyan-100 dark:bg-cyan-950/50 dark:text-cyan-300 dark:ring-cyan-900', value: 'text-cyan-800 dark:text-cyan-200', bar: 'bg-cyan-500' },
    green: { icon: 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-900', value: 'text-emerald-800 dark:text-emerald-200', bar: 'bg-emerald-500' },
    yellow: { icon: 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-900', value: 'text-amber-800 dark:text-amber-200', bar: 'bg-amber-500' },
};
const tone = (name) => toneClasses[name] ?? toneClasses.navy;
</script>

<template>
    <Head title="Vue d’ensemble" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-6">
        <!-- En-tête : qui, où, quand, et l'action du moment. -->
        <header class="relative overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-0 w-1/2 bg-gradient-to-l from-primary-50/80 to-transparent dark:from-primary-950/30" />
            <div class="relative flex flex-col gap-6 p-6 lg:flex-row lg:items-center lg:justify-between lg:p-7">
                <div class="flex min-w-0 items-center gap-4">
                    <span class="hidden h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary font-heading text-lg font-bold text-white shadow-sm sm:flex">{{ userInitials }}</span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 font-semibold text-primary ring-1 ring-inset ring-primary/20">
                                <Building2 class="h-4 w-4" />{{ page.props.site.name }} · {{ page.props.site.code }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 text-muted-foreground"><CalendarDays class="h-4 w-4" />{{ generatedDate }}</span>
                        </div>
                        <h1 class="mt-2.5 truncate font-heading text-2xl font-bold tracking-tight text-foreground sm:text-[28px]">
                            {{ greeting }}, {{ user.name }}
                        </h1>
                        <p class="mt-1 text-sm text-muted-foreground">
                            <span class="font-semibold text-muted-foreground">{{ roleName }}</span>
                            <template v-if="focus.lead"> · {{ focus.lead }}</template>
                            <template v-if="passagesToday !== null"> · <strong class="font-semibold text-foreground">{{ passagesToday }}</strong> passage{{ passagesToday > 1 ? 's' : '' }} aujourd’hui</template>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:shrink-0 lg:flex-nowrap lg:justify-end">
                    <Link
                        v-for="link in quickLinks"
                        :key="link.link"
                        :href="link.link"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-2.5 text-sm font-semibold text-foreground transition hover:border-primary/40 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 "
                    >
                        <component :is="link.icon" class="h-4 w-4" />{{ link.title }}
                    </Link>
                    <Link
                        v-if="primaryAction"
                        :href="primaryAction.link"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:ring-offset-2 dark:"
                    >
                        <component :is="primaryAction.icon" class="h-4 w-4" />{{ primaryAction.label }}
                    </Link>
                </div>
            </div>
        </header>

        <!-- Ce qui attend une décision passe avant les compteurs. -->
        <component
            :is="pendingOrientation.href ? Link : 'div'"
            v-if="pendingOrientation?.value > 0 && pendingOrientation.href"
            :href="pendingOrientation.href || undefined"
            class="group flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-3.5 text-sm text-amber-900 transition hover:border-amber-300 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200"
            role="status"
        >
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-base text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"><CircleAlert class="h-4 w-4" /></span>
            <span class="min-w-0 flex-1">
                <strong class="font-bold">{{ pendingOrientation.value }} passage{{ pendingOrientation.value > 1 ? 's' : '' }} en attente d’orientation</strong>
                <span class="hidden text-amber-800/80 sm:inline dark:text-amber-200/70"> — {{ pendingOrientation.description }}</span>
            </span>
            <ArrowRight class="h-4 w-4 shrink-0 transition-transform group-hover:translate-x-0.5" v-if="pendingOrientation.href" />
        </component>

        <PharmacyHomePanel v-if="pharmacy" v-bind="pharmacy" />

        <HrHomePanel v-if="hr" v-bind="hr" />

        <!-- Indicateurs du jour. -->
        <section v-if="metrics.length" class="space-y-4" aria-labelledby="today-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="today-title" class="font-heading text-base font-bold text-foreground">Activité aujourd’hui</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Calculée à partir des données autorisées pour votre compte.</p>
                </div>
                <span class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"><Clock class="h-4 w-4" />Mis à jour à {{ generatedTime }}</span>
            </div>

            <template v-for="group in metricGroups" :key="group.key">
                <div v-if="group.items.length">
                    <h3 class="mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">{{ group.title }}</h3>
                    <div :class="['grid gap-3', group.grid]">
                        <component
                            :is="metric.href ? Link : 'article'"
                            v-for="metric in group.items"
                            :key="metric.key"
                            :href="metric.href || undefined"
                            :title="metric.description"
                            :class="[
                                'group relative flex flex-col overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm transition ',
                                metric.href ? 'hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 ' : '',
                            ]"
                        >
                            <span :class="['absolute inset-x-0 top-0 h-0.5 opacity-0 transition-opacity group-hover:opacity-100', tone(metric.tone).bar]" aria-hidden="true" />
                            <div class="flex items-start justify-between gap-3">
                                <span :class="['flex h-9 w-9 items-center justify-center rounded-lg text-lg ring-1 ring-inset', tone(metric.tone).icon]"><component :is="lucideIcon(metric.icon)" class="h-4 w-4" /></span>
                                <ArrowRight class="h-4 w-4 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary " v-if="metric.href" />
                            </div>
                            <p :class="['mt-4 font-heading text-3xl font-bold leading-none tabular-nums', metric.value > 0 ? tone(metric.tone).value : 'text-muted-foreground']">{{ metric.value }}</p>
                            <p class="mt-2 text-[13px] font-semibold leading-5 text-foreground">{{ metric.label }}</p>
                            <p class="mt-0.5 line-clamp-2 text-[11px] leading-4 text-muted-foreground">{{ metric.description }}</p>
                        </component>
                    </div>
                </div>
            </template>
        </section>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
            <ActivityTrendChart :trend="overview.trend" />

            <!-- Accès directs : à côté du graphique plutôt qu'en bas de page. -->
            <aside class="rounded-xl border border-border bg-card shadow-sm " aria-labelledby="workspaces-title">
                <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                    <div>
                        <h2 id="workspaces-title" class="font-heading text-base font-bold text-foreground">Vos espaces</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">Modules attribués à votre compte</p>
                    </div>
                    <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-bold tabular-nums text-foreground">{{ workspaces.length }}</span>
                </div>

                <nav v-if="workspaces.length" class="p-2">
                    <div v-for="group in workspaceGroups" :key="group.key" class="[&+&]:mt-2 [&+&]:border-t [&+&]:border-border [&+&]:pt-2">
                        <p v-if="workspaceGroups.length > 1" class="px-3 pb-1 pt-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">{{ group.title }}</p>
                        <Link
                        v-for="workspace in group.items"
                        :key="workspace.title"
                        :href="workspace.link"
                        class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 "
                    >
                        <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-base ring-1 ring-inset', tone(workspace.tone).icon]"><component :is="workspace.icon" class="h-4 w-4" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-foreground group-hover:text-primary">{{ workspace.title }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ workspace.description }}</span>
                        </span>
                        <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary " />
                    </Link>
                    </div>
                </nav>

                <div v-else class="px-6 py-10 text-center">
                    <h3 class="text-sm font-bold text-foreground">Aucun espace métier attribué</h3>
                    <p class="mx-auto mt-1 max-w-xs text-xs leading-5 text-muted-foreground">Votre compte est actif, mais aucune permission de module ne lui est accordée. Contactez l’administrateur local.</p>
                </div>

                <p class="flex items-start gap-2 border-t border-border px-5 py-3.5 text-[11px] leading-4 text-muted-foreground">
                    <ShieldCheck class="h-4 w-4 mt-px shrink-0 text-primary" />
                    Données et raccourcis filtrés selon vos permissions.
                </p>
            </aside>
        </div>
    </div>
</template>
