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
    LayoutGrid,
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

const metricGroups = computed(() => [
    { key: 'focus', title: 'Votre activité', items: focusMetrics.value },
    { key: 'other', title: focusMetrics.value.length ? 'Autres indicateurs accessibles' : 'Indicateurs accessibles', items: otherMetrics.value },
].filter((group) => group.items.length));

const pendingOrientation = computed(() => metrics.value.find((metric) => metric.key === 'pending_orientation'));

const toneClasses = {
    navy: { icon: 'bg-primary/[0.08] text-primary ring-primary/15', value: 'text-foreground' },
    ocean: { icon: 'bg-primary/[0.08] text-primary ring-primary/15', value: 'text-foreground' },
    cyan: { icon: 'bg-primary/[0.08] text-primary ring-primary/15', value: 'text-foreground' },
    green: { icon: 'bg-emerald-50/70 text-emerald-700 ring-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900', value: 'text-foreground' },
    yellow: { icon: 'bg-amber-50/70 text-amber-700 ring-amber-100 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900', value: 'text-foreground' },
    // Neutre, volontairement : le registre des décès (ADR-107) ne porte
    // ni la couleur d'accent ni celle d'une alerte à traiter.
    slate: { icon: 'bg-muted text-muted-foreground ring-border', value: 'text-foreground' },
};
const tone = (name) => toneClasses[name] ?? toneClasses.navy;
</script>

<template>
    <Head title="Vue d’ensemble" />

    <div class="mx-auto w-full max-w-screen-2xl space-y-5">
        <!-- Une entrée calme : identité et contexte à gauche, prochain geste à droite. -->
        <Card class="overflow-hidden">
            <div class="grid xl:grid-cols-[minmax(0,1fr)_420px]">
                <header class="flex min-w-0 items-start gap-4 p-5 sm:items-center sm:gap-5 sm:p-6">
                    <Avatar :initials="userInitials" size="lg" variant="primary-pale" class="h-12 w-12 border border-primary/10 sm:h-14 sm:w-14" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-muted-foreground">
                            <Badge variant="outline" class="font-medium text-foreground">
                                <Building2 class="h-3.5 w-3.5 text-primary" />{{ page.props.site.name }} · {{ page.props.site.code }}
                            </Badge>
                            <span class="inline-flex items-center gap-1.5"><CalendarDays class="h-3.5 w-3.5" />{{ generatedDate }}</span>
                        </div>
                        <h1 class="mt-3 truncate font-heading text-2xl font-bold tracking-tight text-foreground sm:text-[28px]">
                            {{ greeting }}, {{ user.name }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <Badge variant="secondary">{{ roleName }}</Badge>
                            <p v-if="focus.lead" class="text-xs text-muted-foreground sm:text-sm">{{ focus.lead }}</p>
                        </div>
                    </div>
                </header>

                <aside v-if="quickLinks.length || primaryAction" class="border-t border-border bg-muted/20 p-4 sm:p-5 xl:border-l xl:border-t-0">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-foreground">Accès rapides</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">Vos outils les plus utilisés</p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"><Clock class="h-3.5 w-3.5" />{{ generatedTime }}</span>
                    </div>

                    <div class="space-y-2">
                        <Button
                            v-if="primaryAction"
                            :as="Link"
                            :href="primaryAction.link"
                            size="sm"
                            class="w-full justify-between px-3"
                        >
                            <span class="inline-flex min-w-0 items-center gap-2"><component :is="primaryAction.icon" class="h-4 w-4" /><span class="truncate">{{ primaryAction.label }}</span></span>
                            <ArrowRight class="h-4 w-4" />
                        </Button>
                        <div v-if="quickLinks.length" class="grid gap-2 sm:grid-cols-2">
                            <Button
                                v-for="link in quickLinks"
                                :key="link.link"
                                :as="Link"
                                :href="link.link"
                                variant="outline"
                                size="sm"
                                class="w-full justify-start bg-card px-3 shadow-none"
                            >
                                <component :is="link.icon" class="h-4 w-4 text-muted-foreground" /><span class="truncate">{{ link.title }}</span>
                            </Button>
                        </div>
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

        <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="min-w-0 space-y-5">
                <!-- Les indicateurs forment un seul bloc : aucun grand vide quand il n'en existe qu'un. -->
                <Card v-if="metrics.length" class="overflow-hidden" aria-labelledby="today-title">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <h2 id="today-title" class="font-heading text-base font-bold text-foreground">Activité aujourd’hui</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">Indicateurs disponibles pour votre compte.</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"><Clock class="h-4 w-4" />Mis à jour à {{ generatedTime }}</span>
                    </div>

                    <section
                        v-for="group in metricGroups"
                        :key="group.key"
                        :aria-label="group.title"
                        class="p-3 [&+&]:border-t [&+&]:border-border"
                    >
                        <h3 class="px-1 pb-2 text-[10px] font-bold uppercase tracking-[0.14em] text-muted-foreground">{{ group.title }}</h3>
                        <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-2">
                            <component
                                :is="metric.href ? Link : 'div'"
                                v-for="metric in group.items"
                                :key="metric.key"
                                :href="metric.href || undefined"
                                :title="metric.description"
                                :class="[
                                    'group flex min-w-0 items-center gap-3 rounded-lg border border-border/70 bg-background px-3 py-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    metric.href ? 'transition-colors hover:border-primary/25 hover:bg-accent/45' : '',
                                ]"
                            >
                                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-lg ring-1 ring-inset', tone(metric.tone).icon]"><component :is="lucideIcon(metric.icon)" class="h-5 w-5" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline gap-2">
                                        <strong :class="['text-2xl font-bold leading-none tabular-nums', metric.value > 0 ? tone(metric.tone).value : 'text-muted-foreground']">{{ metric.value }}</strong>
                                        <span class="truncate text-xs font-semibold text-foreground">{{ metric.label }}</span>
                                    </span>
                                    <span class="mt-1 block truncate text-[11px] leading-4 text-muted-foreground">{{ metric.description }}</span>
                                </span>
                                <ArrowRight v-if="metric.href" class="h-4 w-4 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary" />
                            </component>
                        </div>
                    </section>
                </Card>

                <ActivityTrendChart :trend="overview.trend" />
            </main>

            <aside class="space-y-5 xl:sticky xl:top-24">
                <!-- Accès directs : visibles au même niveau que l'activité du jour. -->
                <Card aria-labelledby="workspaces-title" class="overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div class="flex items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><LayoutGrid class="h-4 w-4" /></span>
                            <div>
                                <h2 id="workspaces-title" class="font-heading text-base font-bold text-foreground">Vos espaces de travail</h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">Accès disponibles pour ce compte</p>
                            </div>
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
                                class="group flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-accent/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                            >
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted/70 text-muted-foreground ring-1 ring-inset ring-border"><component :is="workspace.icon" class="h-4 w-4" /></span>
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

                    <p class="flex items-start gap-2 border-t border-border bg-muted/20 px-5 py-3.5 text-[11px] leading-4 text-muted-foreground">
                        <ShieldCheck class="mt-px h-4 w-4 shrink-0" />
                        Données et raccourcis filtrés selon vos permissions.
                    </p>
                </Card>

                <PatientDemographicsChart v-if="overview.patient_demographics" :demographics="overview.patient_demographics" />
            </aside>
        </div>
    </div>
</template>
