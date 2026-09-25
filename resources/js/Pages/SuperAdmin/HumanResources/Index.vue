<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Briefcase,
    Building2,
    CheckCircle2,
    CircleSlash,
    LayoutGrid,
    RefreshCw,
    ServerOff,
    Users,
    WifiOff,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrFigures from '@/Components/Administration/HrFigures.vue';
import { lucideIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { usePermissions } from '@/composables/usePermissions';
import { HR_FIGURE_TONES, HR_FIGURES, isVisibleFigure, pendingTotal } from '@/utilities/hrFigures';
import { mapHrPath } from '@/utilities/hrPath';
import { hrSections } from '@/utilities/hrSections';

defineOptions({ layout: AppLayout });

/**
 * ADR-066 / ADR-182 — les Ressources humaines des sites, vues du portail.
 *
 * La vue d'ensemble compare les sites et dit où une décision attend ; chaque
 * chiffre ouvre sa liste dans l'espace RH du site. La vue d'un site donne ses
 * chiffres, ses rubriques et son effectif par département. Les données sont
 * celles que l'API de chaque site vient de renvoyer : rien n'est lu ailleurs.
 */
const props = defineProps({ sites: Array, summary: Object });

const ALL = 'ALL';
const PAGE_URL = '/super-admin/workspaces/hr';

const page = usePage();
const { can } = usePermissions();

// Le site choisi vit dans l'adresse (`?site=A`) : une actualisation ou un
// lien partagé rouvre le même site, sans réinterroger les trois.
const selected = computed(() => {
    const code = new URLSearchParams(page.url.split('?')[1] ?? '').get('site');

    return props.sites.some((site) => site.site.code === code) ? code : ALL;
});

const select = (code) => router.replace({
    url: code === ALL ? PAGE_URL : `${PAGE_URL}?site=${code}`,
    preserveState: true,
    preserveScroll: true,
});

const refreshing = ref(false);
const refresh = () => router.reload({
    preserveScroll: true,
    onStart: () => { refreshing.value = true; },
    onFinish: () => { refreshing.value = false; },
});

const hrBase = (code) => `/super-admin/sites/${code}/rh`;
const figureUrl = (figure, code) => mapHrPath(figure.href, hrBase(code));

const STATUSES = {
    ONLINE: { label: 'Connecté', variant: 'success', dot: 'bg-emerald-500', icon: CheckCircle2 },
    UNCONFIGURED: { label: 'API non configurée', variant: 'outline', dot: 'bg-muted-foreground/40', icon: CircleSlash },
    OFFLINE: { label: 'Hors ligne', variant: 'destructive', dot: 'bg-red-500', icon: WifiOff },
};

const statusOf = (site) => (site.ok ? STATUSES.ONLINE : site.status === 'UNCONFIGURED' ? STATUSES.UNCONFIGURED : STATUSES.OFFLINE);

const summaryOf = (site) => site.data?.summary ?? {};
const onlineSites = computed(() => props.sites.filter((site) => site.ok));
const selectedSite = computed(() => props.sites.find((site) => site.site.code === selected.value) ?? null);

/** Les colonnes du comparatif : les chiffres qu'au moins un site connecté laisse voir. */
const columns = computed(() => HR_FIGURES.filter((figure) => onlineSites.value.some((site) => isVisibleFigure(summaryOf(site), figure.key))));
const todoColumns = computed(() => columns.value.filter((column) => column.group === 'todo'));
const headcountColumns = computed(() => columns.value.filter((column) => column.group === 'headcount'));

/** Ce qui attend une décision, site par site, avec le lien vers la liste. */
const pendingBySite = computed(() => onlineSites.value
    .map((site) => ({
        site,
        total: pendingTotal(summaryOf(site)),
        items: HR_FIGURES
            .filter((figure) => figure.group === 'todo' && Number(summaryOf(site)[figure.key] || 0) > 0)
            .map((figure) => ({ ...figure, count: summaryOf(site)[figure.key], href: figureUrl(figure, site.site.code) })),
    }))
    .filter((entry) => entry.total > 0));

const sections = computed(() => (selectedSite.value?.ok ? hrSections(hrBase(selectedSite.value.site.code), can) : []));
const departments = computed(() => selectedSite.value?.data?.departments ?? []);
const departmentTotal = computed(() => departments.value.reduce((total, department) => total + department.employees_count, 0));
const maxDepartment = computed(() => Math.max(1, ...departments.value.map((department) => department.employees_count)));

const share = (count) => (departmentTotal.value ? Math.round((count / departmentTotal.value) * 100) : 0);
const plural = (count, word) => `${count} ${word}${count > 1 ? 's' : ''}`;
</script>

<template>
    <Head title="Ressources humaines" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Super Administration"
            title="Ressources humaines"
            description="Le personnel des cliniques, site par site : ce qui attend une décision, l’effectif, et l’accès à l’espace RH de chaque site par son API."
            icon="briefcase"
            tone="primary"
        >
            <template #actions>
                <span class="inline-flex h-9 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm text-muted-foreground">
                    <span :class="['h-2 w-2 rounded-full', summary.online_sites === sites.length ? 'bg-emerald-500' : 'bg-amber-500']" />
                    {{ summary.online_sites }} / {{ sites.length }} sites connectés
                </span>
                <Button variant="outline" size="sm" :disabled="refreshing" @click="refresh">
                    <RefreshCw :class="cn('h-4 w-4', refreshing && 'animate-spin')" />Actualiser
                </Button>
            </template>
        </PageHeader>

        <!-- Vue d'ensemble, puis un onglet par site, avec ce qui l'attend. -->
        <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-sm" role="tablist" aria-label="Choisir un site">
            <button
                type="button"
                role="tab"
                :aria-selected="selected === ALL"
                :class="cn('inline-flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', selected === ALL ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground')"
                @click="select(ALL)"
            ><LayoutGrid class="h-4 w-4" />Vue d’ensemble</button>
            <button
                v-for="site in sites"
                :key="site.site.code"
                type="button"
                role="tab"
                :aria-selected="selected === site.site.code"
                :class="cn('inline-flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', selected === site.site.code ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground')"
                @click="select(site.site.code)"
            >
                <span :class="['h-2 w-2 rounded-full', statusOf(site).dot]" :title="statusOf(site).label" />
                {{ site.site.name }}
                <span
                    v-if="site.ok && pendingTotal(summaryOf(site))"
                    :class="cn('grid h-5 min-w-5 place-items-center rounded-full px-1.5 text-[11px] font-bold tabular-nums', selected === site.site.code ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200')"
                    :title="`${pendingTotal(summaryOf(site))} élément(s) à traiter`"
                >{{ pendingTotal(summaryOf(site)) }}</span>
            </button>
        </div>

        <!-- ─────────────── Vue d'ensemble ─────────────── -->
        <template v-if="selected === ALL">
            <section aria-labelledby="hr-all-figures">
                <h2 id="hr-all-figures" class="mb-3 font-heading text-base font-bold text-foreground">
                    Tous les sites connectés — aujourd’hui
                </h2>
                <HrFigures v-if="onlineSites.length" :summary="summary" />
                <Card v-else class="flex items-center gap-3 p-5 text-sm text-muted-foreground">
                    <ServerOff class="h-5 w-5 shrink-0" />Aucun site ne répond pour l’instant : aucun chiffre à additionner.
                </Card>
            </section>

            <!-- Où une décision attend, et le chemin pour la prendre. -->
            <Card v-if="onlineSites.length" class="p-5">
                <div class="flex items-start gap-3">
                    <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', pendingBySite.length ? HR_FIGURE_TONES.amber : HR_FIGURE_TONES.emerald)">
                        <component :is="pendingBySite.length ? lucideIcon('calendar') : CheckCircle2" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-heading text-base font-bold text-foreground">À traiter</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ pendingBySite.length ? 'Ce qui attend une décision, site par site. Chaque ligne ouvre la liste sur le site concerné.' : 'Rien n’attend de décision sur les sites connectés.' }}
                        </p>
                    </div>
                </div>
                <ul v-if="pendingBySite.length" class="mt-4 divide-y divide-border rounded-lg border border-border">
                    <li v-for="entry in pendingBySite" :key="entry.site.site.code" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center">
                        <span class="flex min-w-40 items-center gap-2 text-sm font-semibold text-foreground">
                            <Building2 class="h-4 w-4 text-muted-foreground" />{{ entry.site.site.name }}
                        </span>
                        <div class="flex flex-1 flex-wrap gap-2">
                            <Link
                                v-for="item in entry.items"
                                :key="item.key"
                                :href="item.href"
                                class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 transition hover:border-amber-300 hover:bg-amber-100 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                            ><span class="tabular-nums">{{ item.count }}</span> {{ item.short.toLowerCase() }}<ArrowRight class="h-3 w-3" /></Link>
                        </div>
                    </li>
                </ul>
            </Card>

            <!-- Le comparatif : une ligne par site, chaque chiffre ouvre sa liste. -->
            <Card class="overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-4">
                    <div>
                        <h2 class="font-heading text-base font-bold text-foreground">Comparer les sites</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">Un chiffre ouvre la liste correspondante dans l’espace RH du site.</p>
                    </div>
                </div>

                <!-- Écran large : un tableau. -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead>
                            <!-- Deux familles de chiffres : ce qui attend une décision, puis l'effectif. -->
                            <tr class="bg-muted/40 text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">
                                <th scope="colgroup" class="px-5 pt-3" />
                                <th v-if="todoColumns.length" scope="colgroup" :colspan="todoColumns.length" class="border-b border-amber-300/60 px-3 pt-3 text-right text-amber-700 dark:border-amber-800 dark:text-amber-300">À traiter</th>
                                <th v-if="headcountColumns.length" scope="colgroup" :colspan="headcountColumns.length" class="border-b border-border px-3 pt-3 text-right">Effectif du jour</th>
                                <th scope="colgroup" />
                            </tr>
                            <tr class="border-b border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">
                                <th scope="col" class="whitespace-nowrap px-5 py-2.5">Site</th>
                                <th v-for="column in columns" :key="column.key" scope="col" class="whitespace-nowrap px-3 py-2.5 text-right" :title="column.label">{{ column.short }}</th>
                                <th scope="col" class="px-5 py-2.5 text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="site in sites" :key="site.site.code" class="border-b border-border last:border-0">
                                <td class="px-5 py-3">
                                    <button type="button" class="flex items-center gap-2.5 text-left font-semibold text-foreground hover:text-primary" @click="select(site.site.code)">
                                        <span :class="['h-2 w-2 shrink-0 rounded-full', statusOf(site).dot]" />
                                        <span>
                                            {{ site.site.name }}
                                            <span v-if="! site.ok" class="block text-xs font-normal text-muted-foreground">{{ statusOf(site).label }}</span>
                                        </span>
                                    </button>
                                </td>
                                <td v-for="column in columns" :key="column.key" class="px-3 py-3 text-right tabular-nums">
                                    <template v-if="site.ok && isVisibleFigure(summaryOf(site), column.key)">
                                        <Link
                                            :href="figureUrl(column, site.site.code)"
                                            :class="cn(
                                                'inline-flex min-w-9 justify-end rounded-md px-2 py-1 font-semibold transition hover:bg-accent',
                                                column.group === 'todo' && summaryOf(site)[column.key] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-foreground',
                                                ! summaryOf(site)[column.key] && 'text-muted-foreground/60',
                                            )"
                                            :title="`${column.label} — ${site.site.name}`"
                                        >{{ summaryOf(site)[column.key] }}</Link>
                                    </template>
                                    <span v-else class="px-2 text-muted-foreground/60" :title="site.ok ? 'Non visible avec vos droits' : statusOf(site).label">—</span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <Button v-if="site.ok" :as="Link" :href="hrBase(site.site.code)" variant="outline" size="xs">
                                        Gérer<ArrowRight class="h-3.5 w-3.5" />
                                    </Button>
                                    <span v-else class="text-xs text-muted-foreground">Indisponible</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="onlineSites.length > 1">
                            <tr class="bg-muted/40 font-semibold">
                                <td class="px-5 py-3 text-foreground">Total des sites connectés</td>
                                <td v-for="column in columns" :key="column.key" class="px-3 py-3 text-right tabular-nums text-foreground">
                                    <span class="px-2">{{ isVisibleFigure(summary, column.key) ? summary[column.key] : '—' }}</span>
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Téléphone : une carte par site. -->
                <ul class="divide-y divide-border md:hidden">
                    <li v-for="site in sites" :key="site.site.code" class="space-y-3 px-4 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <button type="button" class="flex items-center gap-2 font-semibold text-foreground" @click="select(site.site.code)">
                                <span :class="['h-2 w-2 rounded-full', statusOf(site).dot]" />{{ site.site.name }}
                            </button>
                            <Badge :variant="statusOf(site).variant">{{ statusOf(site).label }}</Badge>
                        </div>
                        <dl v-if="site.ok" class="grid grid-cols-2 gap-2">
                            <template v-for="column in columns" :key="column.key">
                                <Link
                                    v-if="isVisibleFigure(summaryOf(site), column.key)"
                                    :href="figureUrl(column, site.site.code)"
                                    class="rounded-lg border border-border px-3 py-2 transition hover:bg-accent"
                                >
                                    <dt class="text-[11px] text-muted-foreground">{{ column.short }}</dt>
                                    <dd :class="cn('text-lg font-bold tabular-nums', column.group === 'todo' && summaryOf(site)[column.key] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-foreground')">{{ summaryOf(site)[column.key] }}</dd>
                                </Link>
                            </template>
                        </dl>
                        <p v-else class="text-sm text-muted-foreground">{{ site.message }}</p>
                        <Button v-if="site.ok" :as="Link" :href="hrBase(site.site.code)" size="sm" class="w-full">
                            <Briefcase class="h-4 w-4" />Gérer les RH de {{ site.site.name }}
                        </Button>
                    </li>
                </ul>
            </Card>
        </template>

        <!-- ─────────────── Un site ─────────────── -->
        <template v-else-if="selectedSite">
            <Card class="p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-heading text-xl font-bold text-foreground">{{ selectedSite.site.name }}</h2>
                                <Badge :variant="statusOf(selectedSite).variant"><component :is="statusOf(selectedSite).icon" class="h-3.5 w-3.5" />{{ statusOf(selectedSite).label }}</Badge>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ selectedSite.ok
                                    ? 'Chiffres renvoyés à l’instant par l’API du site. Les dossiers restent sur le site ; vous y agissez avec ses règles, et chaque geste est tracé à votre nom.'
                                    : selectedSite.status === 'UNCONFIGURED'
                                        ? 'L’adresse ou le jeton de l’API de ce site ne sont pas réglés sur le portail : ses RH ne peuvent être ni lues ni gérées d’ici. Les autres sites restent disponibles.'
                                        : 'Le site ne répond pas pour l’instant. Les autres sites restent disponibles ; réessayez dans un moment.' }}
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <Button v-if="selectedSite.ok" :as="Link" :href="hrBase(selectedSite.site.code)">
                            <Briefcase class="h-4 w-4" />Gérer les RH<ArrowRight class="h-4 w-4" />
                        </Button>
                        <Button v-else-if="selectedSite.status !== 'UNCONFIGURED'" variant="outline" :disabled="refreshing" @click="refresh">
                            <RefreshCw :class="cn('h-4 w-4', refreshing && 'animate-spin')" />Réessayer
                        </Button>
                    </div>
                </div>

                <!-- Les rubriques de l'espace RH du site, une par bouton. -->
                <nav v-if="sections.length" class="mt-5 border-t border-border pt-4" aria-label="Accès direct aux rubriques RH">
                    <p class="mb-2 text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Accès direct</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        <Link
                            v-for="section in sections"
                            :key="section.code"
                            :href="section.href"
                            class="group flex items-center gap-2.5 rounded-lg border border-border px-3 py-2.5 text-sm font-semibold text-foreground transition hover:border-primary/40 hover:bg-accent"
                        >
                            <component :is="section.icon" class="h-4 w-4 text-muted-foreground group-hover:text-primary" />
                            <span class="truncate">{{ section.label }}</span>
                        </Link>
                    </div>
                </nav>
            </Card>

            <template v-if="selectedSite.ok">
                <section aria-labelledby="hr-site-figures">
                    <h2 id="hr-site-figures" class="mb-3 font-heading text-base font-bold text-foreground">Aujourd’hui</h2>
                    <!-- Chaque chiffre ouvre sa liste dans l'espace RH de ce site. -->
                    <HrFigures :summary="summaryOf(selectedSite)" linkable :base="hrBase(selectedSite.site.code)" />
                </section>

                <Card class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', HR_FIGURE_TONES.sky)"><Users class="h-5 w-5" /></span>
                            <div>
                                <h2 class="font-heading text-base font-bold text-foreground">Effectif par département</h2>
                                <p class="mt-0.5 text-sm text-muted-foreground">{{ plural(departmentTotal, 'employé actif') }} à {{ selectedSite.site.name }}.</p>
                            </div>
                        </div>
                        <Button :as="Link" :href="`${hrBase(selectedSite.site.code)}/employees`" variant="ghost" size="sm">
                            Voir les employés<ArrowRight class="h-4 w-4" />
                        </Button>
                    </div>
                    <ul v-if="departments.length" class="mt-4 space-y-3">
                        <li v-for="department in departments" :key="department.uuid || department.label">
                            <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                                <span class="truncate text-foreground">{{ department.label }}</span>
                                <span class="shrink-0 tabular-nums text-muted-foreground"><strong class="text-foreground">{{ department.employees_count }}</strong> · {{ share(department.employees_count) }} %</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                <div class="h-full rounded-full bg-primary" :style="{ width: `${Math.max(4, Math.round((department.employees_count / maxDepartment) * 100))}%` }" />
                            </div>
                        </li>
                    </ul>
                    <div v-else class="mt-4 rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        Aucun employé actif à répartir.
                        <Link v-if="can('employees.create')" :href="`${hrBase(selectedSite.site.code)}/employees/create`" class="ms-1 font-semibold text-primary hover:underline">Créer le premier dossier</Link>
                    </div>
                </Card>
            </template>

        </template>
    </div>
</template>
