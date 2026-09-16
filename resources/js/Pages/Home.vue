<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityTrendChart from '@/Components/Dashboard/ActivityTrendChart.vue';
import PatientDemographicsChart from '@/Components/Dashboard/PatientDemographicsChart.vue';
import PharmacyHomePanel from '@/Components/Pharmacy/PharmacyHomePanel.vue';
import HrHomePanel from '@/Components/Administration/HrHomePanel.vue';
import Avatar from '@/Components/Shadcn/Avatar.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
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
        default: () => ({ generated_at: null, metrics: [], patient_demographics: null, trend: { dates: [], series: [] } }),
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
        <Card class="relative overflow-hidden border-primary/15">
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-primary via-sky-500 to-emerald-500" />
            <div aria-hidden="true" class="pointer-events-none absolute -left-20 -top-24 h-64 w-64 rounded-full bg-primary/10 blur-3xl" />
            <div aria-hidden="true" class="pointer-events-none absolute -bottom-28 left-1/2 h-56 w-56 rounded-full bg-sky-400/10 blur-3xl" />

            <div class="relative grid xl:grid-cols-[minmax(0,1fr)_480px]">
                <header class="flex min-w-0 items-center gap-4 p-5 sm:gap-5 sm:p-6 lg:p-7">
                    <Avatar :initials="userInitials" size="lg" variant="primary-pale" class="h-14 w-14 ring-4 ring-primary/10 sm:h-16 sm:w-16" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <Badge class="border-primary/20 bg-primary/10 text-primary" variant="outline">
                                <Building2 class="h-3.5 w-3.5" />{{ page.props.site.name }} · {{ page.props.site.code }}
                            </Badge>
                            <span class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"><CalendarDays class="h-3.5 w-3.5" />{{ generatedDate }}</span>
                        </div>
                        <h1 class="mt-3 truncate font-heading text-2xl font-bold tracking-tight text-foreground sm:text-[28px]">
                            {{ greeting }}, {{ user.name }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">{{ roleName }}</Badge>
                        </div>
                    </div>
                </header>

                <aside v-if="quickLinks.length || primaryAction" class="border-t border-border bg-muted/25 p-4 sm:p-5 xl:border-l xl:border-t-0">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold text-foreground">Actions rapides</p>
                            <p class="mt-0.5 text-[11px] text-muted-foreground">Accès adaptés à votre profil</p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 text-[11px] text-muted-foreground"><Clock class="h-3.5 w-3.5" />{{ generatedTime }}</span>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <Button
                            v-if="primaryAction"
                            :as="Link"
                            :href="primaryAction.link"
                            size="sm"
                            class="w-full justify-start"
                        >
                            <component :is="primaryAction.icon" class="h-4 w-4" />{{ primaryAction.label }}
                        </Button>
                        <Button
                            v-for="link in quickLinks"
                            :key="link.link"
                            :as="Link"
                            :href="link.link"
                            variant="outline"
                            size="sm"
                            class="w-full justify-start bg-card"
                        >
                            <component :is="link.icon" class="h-4 w-4 text-primary" />{{ link.title }}
                        </Button>
                    </div>
                </aside>
            </div>
        </Card>

        <!-- Ce qui attend une décision passe avant les compteurs. -->
        <Card v-if="pendingOrientation?.value > 0 && pendingOrientation.href" class="border-amber-200 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/20">
            <Link :href="pendingOrientation.href" class="group flex items-center gap-3 px-4 py-3.5 text-sm text-amber-950 sm:px-5 dark:text-amber-100" role="status">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/50 dark:text-amber-300 dark:ring-amber-800"><CircleAlert class="h-4 w-4" /></span>
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <strong class="font-bold">Passages à orienter</strong>
                        <Badge variant="warning">{{ pendingOrientation.value }}</Badge>
                    </span>
                    <span class="mt-0.5 block text-xs text-amber-800/80 dark:text-amber-200/70">{{ pendingOrientation.description }}</span>
                </span>
                <ArrowRight class="h-4 w-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
            </Link>
        </Card>

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
                        <Card
                            v-for="metric in group.items"
                            :key="metric.key"
                            :class="[
                                'group relative overflow-hidden transition',
                                metric.href ? 'hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md' : '',
                            ]"
                        >
                            <component
                                :is="metric.href ? Link : 'div'"
                                :href="metric.href || undefined"
                                :title="metric.description"
                                class="relative flex h-full min-h-40 flex-col p-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                            >
                                <span :class="['absolute inset-x-0 top-0 h-0.5 opacity-0 transition-opacity group-hover:opacity-100', tone(metric.tone).bar]" aria-hidden="true" />
                                <div class="flex items-start justify-between gap-3">
                                    <span :class="['flex h-9 w-9 items-center justify-center rounded-lg ring-1 ring-inset', tone(metric.tone).icon]"><component :is="lucideIcon(metric.icon)" class="h-4 w-4" /></span>
                                    <ArrowRight v-if="metric.href" class="h-4 w-4 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary" />
                                </div>
                                <p :class="['mt-4 font-heading text-3xl font-bold leading-none tabular-nums', metric.value > 0 ? tone(metric.tone).value : 'text-muted-foreground']">{{ metric.value }}</p>
                                <p class="mt-2 text-[13px] font-semibold leading-5 text-foreground">{{ metric.label }}</p>
                                <p class="mt-0.5 line-clamp-2 text-[11px] leading-4 text-muted-foreground">{{ metric.description }}</p>
                            </component>
                        </Card>
                    </div>
                </div>
            </template>
        </section>

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
            <ActivityTrendChart :trend="overview.trend" />

            <div class="space-y-5">
                <PatientDemographicsChart v-if="overview.patient_demographics" :demographics="overview.patient_demographics" />

                <!-- Accès directs : à côté du graphique plutôt qu'en bas de page. -->
                <Card aria-labelledby="workspaces-title" class="overflow-hidden">
                <aside>
                    <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <h2 id="workspaces-title" class="font-heading text-base font-bold text-foreground">Vos espaces</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">Modules attribués à votre compte</p>
                        </div>
                        <Badge variant="secondary" class="tabular-nums">{{ workspaces.length }}</Badge>
                    </div>

                    <nav v-if="workspaces.length" class="p-2">
                        <div v-for="group in workspaceGroups" :key="group.key" class="[&+&]:mt-2 [&+&]:border-t [&+&]:border-border [&+&]:pt-2">
                            <p v-if="workspaceGroups.length > 1" class="px-3 pb-1 pt-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">{{ group.title }}</p>
                            <Link
                                v-for="workspace in group.items"
                                :key="workspace.title"
                                :href="workspace.link"
                                class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                            >
                                <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset', tone(workspace.tone).icon]"><component :is="workspace.icon" class="h-4 w-4" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-foreground group-hover:text-primary">{{ workspace.title }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">{{ workspace.description }}</span>
                                </span>
                                <ChevronRight class="h-4 w-4 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary" />
                            </Link>
                        </div>
                    </nav>

                    <div v-else class="px-6 py-10 text-center">
                        <h3 class="text-sm font-bold text-foreground">Aucun espace métier attribué</h3>
                        <p class="mx-auto mt-1 max-w-xs text-xs leading-5 text-muted-foreground">Votre compte est actif, mais aucune permission de module ne lui est accordée. Contactez l’administrateur local.</p>
                    </div>

                    <p class="flex items-start gap-2 border-t border-border bg-muted/30 px-5 py-3.5 text-[11px] leading-4 text-muted-foreground">
                        <ShieldCheck class="mt-px h-4 w-4 shrink-0 text-primary" />
                        Données et raccourcis filtrés selon vos permissions.
                    </p>
                </aside>
                </Card>
            </div>
        </div>
    </div>
</template>
