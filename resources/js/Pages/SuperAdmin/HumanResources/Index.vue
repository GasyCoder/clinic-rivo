<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Briefcase, Building2, CheckCircle2, CircleSlash, LayoutGrid, ServerOff, ShieldCheck, WifiOff } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import RefreshIcon from '@/Components/Shadcn/RefreshIcon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import HrFigures from '@/Components/Administration/HrFigures.vue';
import { lucideIcon } from '@/lib/icons';
import { cn } from '@/lib/cn';
import { HR_FIGURE_TONES, HR_FIGURES, isVisibleFigure, pendingTotal } from '@/utilities/hrFigures';
import { mapHrPath } from '@/utilities/hrPath';

defineOptions({ layout: AppLayout });

/**
 * ADR-066 / ADR-187 — les Ressources humaines de tous les sites, comparées.
 *
 * L'accueil RH d'un site n'existe qu'une fois : l'écran du site lui-même,
 * relayé par son API (`/super-admin/sites/{code}/rh`) — le même que voit le RH
 * du site. Cette page ne le recopie pas : chaque onglet de site y mène, et elle
 * garde ce qu'aucun site ne peut montrer seul — ce qui attend une décision,
 * site par site, et le comparatif. Les données sont celles que l'API de chaque
 * site vient de renvoyer : rien n'est lu ailleurs.
 */
const props = defineProps({ sites: Array, summary: Object });

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

const tabClass = 'inline-flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <Head title="Ressources humaines" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Ressources humaines"
            title="Tous les sites"
            description="Le personnel des cliniques, comparé : ce qui attend une décision, l’effectif, et l’accès à l’accueil RH de chaque site — le même que celui du RH du site."
            icon="briefcase"
            tone="primary"
        >
            <template #actions>
                <Button variant="outline" :aria-busy="refreshing" :disabled="refreshing" @click="refresh">
                    <RefreshIcon :spinning="refreshing" class="h-4 w-4" />Actualiser
                </Button>
            </template>
        </PageHeader>

        <!-- Un onglet par site : il ouvre l'accueil RH de ce site. -->
        <div class="flex flex-wrap items-center gap-2">
            <nav class="flex max-w-full gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-sm" aria-label="Accueil RH d’un site">
                <component
                    :is="site.status === 'UNCONFIGURED' ? 'span' : Link"
                    v-for="site in sites"
                    :key="site.site.code"
                    :href="site.status === 'UNCONFIGURED' ? undefined : hrBase(site.site.code)"
                    :aria-disabled="site.status === 'UNCONFIGURED' ? 'true' : undefined"
                    :title="site.status === 'UNCONFIGURED' ? `L’API de ${site.site.name} n’est pas configurée` : `Accueil RH de ${site.site.name}`"
                    :class="cn(tabClass, 'text-muted-foreground hover:bg-accent hover:text-foreground', site.status === 'UNCONFIGURED' && 'cursor-not-allowed opacity-50 hover:bg-transparent')"
                >
                    <Building2 class="h-4 w-4" />
                    {{ site.site.name }}
                    <span :class="['h-2 w-2 rounded-full', statusOf(site).dot]" :title="statusOf(site).label" />
                    <span
                        v-if="site.ok && pendingTotal(summaryOf(site))"
                        class="grid h-5 min-w-5 place-items-center rounded-full bg-amber-100 px-1.5 text-[11px] font-bold tabular-nums text-amber-800 dark:bg-amber-950/60 dark:text-amber-200"
                        :title="`${pendingTotal(summaryOf(site))} élément(s) à traiter`"
                    >{{ pendingTotal(summaryOf(site)) }}</span>
                </component>
                <span :class="cn(tabClass, 'bg-primary text-primary-foreground shadow-sm')" aria-current="page"><LayoutGrid class="h-4 w-4" />Tous les sites</span>
            </nav>
            <span class="ms-auto inline-flex h-9 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm text-muted-foreground">
                <span :class="['h-2 w-2 rounded-full', summary.online_sites === sites.length ? 'bg-emerald-500' : 'bg-amber-500']" />
                {{ summary.online_sites }} / {{ sites.length }} sites connectés
            </span>
        </div>

        <!-- Tous les sites connectés : les chiffres additionnés. -->
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
                                <component :is="site.status === 'UNCONFIGURED' ? 'span' : Link" :href="site.status === 'UNCONFIGURED' ? undefined : hrBase(site.site.code)" class="flex items-center gap-2.5 text-left font-semibold text-foreground hover:text-primary">
                                    <span :class="['h-2 w-2 shrink-0 rounded-full', statusOf(site).dot]" />
                                    <span>
                                        {{ site.site.name }}
                                        <span v-if="! site.ok" class="block text-xs font-normal text-muted-foreground">{{ statusOf(site).label }}</span>
                                    </span>
                                </component>
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
                        <component :is="site.status === 'UNCONFIGURED' ? 'span' : Link" :href="site.status === 'UNCONFIGURED' ? undefined : hrBase(site.site.code)" class="flex items-center gap-2 font-semibold text-foreground">
                            <span :class="['h-2 w-2 rounded-full', statusOf(site).dot]" />{{ site.site.name }}
                        </component>
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

        <p class="flex items-start gap-2 px-1 text-xs text-muted-foreground">
            <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Les dossiers RH restent sur chaque site : vous y agissez par son API, avec ses règles, et chaque modification est tracée à votre nom. Aucune paie n’est calculée automatiquement.
        </p>
    </div>
</template>
