<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    Bold,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    CircleOff,
    Download,
    FileDown,
    FileSpreadsheet,
    FlaskConical,
    LayoutGrid,
    List,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Server,
    Tags,
    Upload,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import SettingsSiteSwitcher from '@/Components/Settings/SettingsSiteSwitcher.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { buildAnalysisTree, flattenAnalysisTree } from '@/utilities/analysisHierarchy';
import { tariffsUrl } from '@/utilities/catalogGroups';

/**
 * ADR-063 — la structure technique d'une analyse est distincte de sa
 * prestation tarifable. Le portail ne lit jamais une base de site : toutes
 * les données et mutations de cet écran passent par les API des cliniques.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    selectedSiteCode: { type: String, default: null },
});

const { can } = usePermissions();
const requestedSite = props.sites.find((site) => site.site.code === props.selectedSiteCode)?.site.code;
const selectedSiteCode = ref(requestedSite ?? props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code ?? '');
const search = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? 'ALL');
const catalogItem = ref(props.filters.catalog_item ?? '');
const pageNumber = ref(1);
const pageSize = 50;
const importOpen = ref(false);
const importInputKey = ref(0);
const expandedGroups = ref(new Set());
const viewMode = ref('list');
const viewStorageKey = 'rivo.view.super-admin-analyses';

// SSR : la première vue reste toujours la liste. La préférence du navigateur
// s'applique seulement après hydratation pour garder le même HTML serveur/client.
onMounted(() => {
    try {
        viewMode.value = localStorage.getItem(viewStorageKey) === 'grid' ? 'grid' : 'list';
    } catch { /* navigation privée : la liste reste la valeur sûre */ }
});
const setViewMode = (mode) => {
    viewMode.value = mode === 'grid' ? 'grid' : 'list';
    try { localStorage.setItem(viewStorageKey, viewMode.value); } catch { /* navigation privée */ }
};

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value) ?? null);
const siteData = computed(() => selectedSite.value?.data ?? {});
const catalogItems = computed(() => siteData.value.catalog_items ?? []);
const summary = computed(() => selectedSite.value?.meta?.summary ?? {
    active: 0,
    inactive: 0,
    services: 0,
    nested_groups: 0,
    displayed: 0,
});

// Une prestation venue de « Tarifs & mutuelles » filtre localement la réponse
// complète du site. Le portail ne déclenche pas une seconde lecture distante.
const analyses = computed(() => (siteData.value.analyses ?? []).filter((analysis) => (
    ! catalogItem.value || analysis.catalog_item?.uuid === catalogItem.value
)));

// L'API renvoie des lignes à plat. On les reconstruit en arbres par prestation
// afin qu'une sous-analyse ne vive plus comme une ligne autonome : elle reste
// enveloppée dans son analyse principale, avec tous ses niveaux descendants.
const analysisTree = computed(() => buildAnalysisTree(analyses.value));
const orderedAnalyses = computed(() => flattenAnalysisTree(analysisTree.value));
const pageCount = computed(() => Math.max(1, Math.ceil(analysisTree.value.length / pageSize)));
const visibleRootAnalyses = computed(() => analysisTree.value.slice(
    (pageNumber.value - 1) * pageSize,
    pageNumber.value * pageSize,
));
const rangeStart = computed(() => analysisTree.value.length ? ((pageNumber.value - 1) * pageSize) + 1 : 0);
const rangeEnd = computed(() => Math.min(pageNumber.value * pageSize, analysisTree.value.length));
const expandableRoots = computed(() => visibleRootAnalyses.value.filter((analysis) => analysis.children.length));
const allVisibleExpanded = computed(() => expandableRoots.value.length > 0
    && expandableRoots.value.every((analysis) => expandedGroups.value.has(analysis.uuid)));

watch(analysisTree, () => {
    pageNumber.value = Math.min(pageNumber.value, pageCount.value);
    expandedGroups.value = new Set();
});

const statusTabs = computed(() => [
    { value: 'ALL', label: 'Toutes', count: Number(summary.value.active) + Number(summary.value.inactive) },
    { value: 'ACTIVE', label: 'Actives', count: summary.value.active },
    { value: 'INACTIVE', label: 'Inactives', count: summary.value.inactive },
]);
const summaryMetrics = computed(() => [
    { key: 'shown', label: 'Affichées', value: analyses.value.length },
    { key: 'active', label: 'Actives', value: summary.value.active },
    { key: 'services', label: 'Prestations liées', value: summary.value.services },
    { key: 'groups', label: 'Sous-groupes', value: summary.value.nested_groups },
]);
const catalogOptions = computed(() => [
    { value: '', label: 'Toutes les prestations' },
    ...catalogItems.value.map((item) => ({ value: item.uuid, label: `${item.code} · ${item.name}` })),
]);
const reachableSiteOptions = computed(() => props.sites
    .filter((site) => site.ok)
    .map((site) => ({ value: site.site.code, label: site.site.name })));
const hasFilters = computed(() => Boolean(search.value || catalogItem.value || status.value !== 'ALL'));
const exportUrl = computed(() => `/super-admin/analyses/export?site_code=${selectedSiteCode.value}&status=${status.value}`);
const createUrl = computed(() => `/super-admin/analyses/${selectedSiteCode.value}/create`);

const importForm = useForm({ site_code: selectedSiteCode.value, file: null });
const importError = computed(() => Object.values(importForm.errors)[0] ?? '');
const excelItems = computed(() => [
    can('analysis_catalog.export') && {
        key: 'export',
        label: 'Exporter la vue',
        description: `${selectedSite.value?.site.name ?? 'Site choisi'} · ${statusTabs.value.find((tab) => tab.value === status.value)?.label.toLowerCase()}`,
        icon: FileDown,
        disabled: ! selectedSite.value?.ok,
    },
    can('analysis_catalog.import') && {
        key: 'import',
        label: 'Importer un fichier',
        description: 'Créer ou mettre à jour par code',
        icon: Upload,
        separatorBefore: can('analysis_catalog.export'),
        disabled: ! reachableSiteOptions.value.length,
    },
    can('analysis_catalog.import') && {
        key: 'template',
        label: 'Télécharger le modèle',
        description: 'Classeur Excel prêt à remplir',
        icon: Download,
    },
].filter(Boolean));

const levelLabel = (value) => ({ PARENT: 'Groupe', CHILD: 'Sous-analyse', NORMAL: 'Analyse simple' }[value] ?? value);
const typeLabel = (value) => ({ NUMERIC: 'Numérique', TEXT: 'Texte', CHOICE: 'Choix', BOOLEAN: 'Oui / Non' }[value] ?? value);
const ancestorPath = (analysis) => (analysis.hierarchy_path ?? '').split(' › ').slice(0, -1).join(' › ');
const referenceLines = (analysis) => [
    ['Gén.', analysis.reference_general],
    ['H', analysis.reference_male],
    ['F', analysis.reference_female],
    ['Garçon', analysis.reference_child_male],
    ['Fille', analysis.reference_child_female],
].filter(([, value]) => value);
const descendantsOf = (analysis) => flattenAnalysisTree(analysis.children ?? []);
const isExpanded = (uuid) => expandedGroups.value.has(uuid);
const toggleGroup = (uuid) => {
    const next = new Set(expandedGroups.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    expandedGroups.value = next;
};
const toggleAllVisible = () => {
    const next = new Set(expandedGroups.value);
    if (allVisibleExpanded.value) {
        expandableRoots.value.forEach((analysis) => next.delete(analysis.uuid));
    } else {
        expandableRoots.value.forEach((analysis) => next.add(analysis.uuid));
    }
    expandedGroups.value = next;
};

const replaceSiteInUrl = (code) => {
    const url = new URL(window.location.href);
    url.searchParams.set('site', code);
    url.searchParams.delete('catalog_item');
    window.history.replaceState(window.history.state, '', url);
};
const selectSite = (code) => {
    selectedSiteCode.value = code;
    catalogItem.value = '';
    pageNumber.value = 1;
    importForm.site_code = code;
    importForm.file = null;
    importForm.clearErrors();
    importOpen.value = false;
    expandedGroups.value = new Set();
    replaceSiteInUrl(code);
};
const selectCatalogItem = (value) => {
    catalogItem.value = value;
    pageNumber.value = 1;
    expandedGroups.value = new Set();

    const url = new URL(window.location.href);
    value ? url.searchParams.set('catalog_item', value) : url.searchParams.delete('catalog_item');
    window.history.replaceState(window.history.state, '', url);
};
const applyFilters = () => {
    expandedGroups.value = new Set();
    router.get('/super-admin/analyses', {
        q: search.value.trim() || undefined,
        status: status.value,
        site: selectedSiteCode.value || undefined,
        catalog_item: catalogItem.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
const selectStatus = (value) => {
    status.value = value;
    pageNumber.value = 1;
    applyFilters();
};
const clearFilters = () => {
    search.value = '';
    status.value = 'ALL';
    catalogItem.value = '';
    pageNumber.value = 1;
    applyFilters();
};
const goToPage = (page) => {
    pageNumber.value = Math.min(Math.max(page, 1), pageCount.value);
    document.querySelector('[data-analysis-table]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const toggleActive = (analysis) => router.post(
    `/super-admin/analyses/${selectedSiteCode.value}/${analysis.uuid}/${analysis.is_active ? 'deactivate' : 'activate'}`,
    {},
    { preserveScroll: true },
);
const analysisActions = (analysis) => [
    can('analysis_catalog.update') && { key: 'edit', label: 'Modifier l’analyse', icon: Pencil },
    can(analysis.is_active ? 'analysis_catalog.deactivate' : 'analysis_catalog.activate') && {
        key: 'toggle',
        label: analysis.is_active ? 'Désactiver' : 'Activer',
        description: analysis.is_active ? 'La retirer des nouvelles demandes' : 'La rendre de nouveau sélectionnable',
        icon: analysis.is_active ? CircleOff : CheckCircle2,
        separatorBefore: can('analysis_catalog.update'),
    },
].filter(Boolean);
const onAnalysisAction = (analysis, key) => {
    if (key === 'edit') router.visit(`/super-admin/analyses/${selectedSiteCode.value}/${analysis.uuid}/edit`);
    if (key === 'toggle') toggleActive(analysis);
};

const openImport = () => {
    importForm.clearErrors();
    importForm.site_code = selectedSite.value?.ok ? selectedSiteCode.value : reachableSiteOptions.value[0]?.value ?? '';
    importForm.file = null;
    importInputKey.value += 1;
    importOpen.value = true;
};
const closeImport = (force = false) => {
    if (importForm.processing && ! force) return;
    importOpen.value = false;
    importForm.reset('file');
    importForm.clearErrors();
    importInputKey.value += 1;
};
const submitImport = () => importForm.post('/super-admin/analyses/import', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => closeImport(true),
});
const onExcel = (key) => {
    if (key === 'export') window.location.assign(exportUrl.value);
    if (key === 'template') window.location.assign('/super-admin/analyses/import-template');
    if (key === 'import') openImport();
};
</script>

<template>
    <Head title="Catalogue des analyses" />

    <div class="w-full space-y-5">
        <PageHeader
            compact
            tone="slate"
            eyebrow="Super Administration · Laboratoire"
            title="Catalogue des analyses"
            description="Structurez les examens, leurs unités et leurs références pour chaque clinique. Les tarifs restent gérés séparément."
            :icon="FlaskConical"
        >
            <template #actions>
                <DropdownMenu v-if="excelItems.length" :items="excelItems" label="Fichiers Excel" @select="onExcel">
                    <template #trigger>
                        <Button type="button" variant="outline"><FileSpreadsheet class="h-4 w-4" />Excel<ChevronDown class="h-4 w-4 text-muted-foreground" /></Button>
                    </template>
                </DropdownMenu>
                <Button
                    v-if="can('analysis_catalog.create')"
                    :as="Link"
                    :href="createUrl"
                    :class="! selectedSite?.ok && 'pointer-events-none opacity-50'"
                    :aria-disabled="! selectedSite?.ok"
                ><Plus class="h-4 w-4" />Nouvelle analyse</Button>
            </template>
        </PageHeader>

        <SettingsSiteSwitcher
            v-if="sites.length"
            :targets="sites"
            :model-value="selectedSiteCode"
            label="Site du catalogue affiché"
            @update:model-value="selectSite"
        />

        <Card v-if="! selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
            <span class="grid h-11 w-11 place-items-center rounded-lg border border-border text-muted-foreground"><Server class="h-5 w-5" /></span>
            <h2 class="mt-3 text-sm font-semibold text-foreground">Catalogue indisponible pour {{ selectedSite?.site.name ?? 'ce site' }}</h2>
            <p class="mt-1 max-w-xl text-sm text-muted-foreground">{{ selectedSite?.message }}</p>
            <p class="mt-3 text-xs text-muted-foreground">Sélectionnez un autre site : une panne n’empêche jamais la consultation des autres cliniques.</p>
        </Card>

        <Card v-else data-analysis-table class="min-w-0 overflow-hidden">
            <header class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FlaskConical class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-semibold text-foreground">{{ selectedSite.site.name }}</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">Catalogue technique du Laboratoire · lecture et écritures via API</p>
                    </div>
                </div>
                <Badge variant="outline">{{ analysisTree.length }} analyse{{ analysisTree.length > 1 ? 's' : '' }} principale{{ analysisTree.length > 1 ? 's' : '' }} · {{ analyses.length }} ligne{{ analyses.length > 1 ? 's' : '' }}</Badge>
            </header>

            <dl class="grid grid-cols-2 border-y border-border bg-muted/25 sm:grid-cols-4 sm:divide-x sm:divide-border">
                <div v-for="metric in summaryMetrics" :key="metric.key" class="px-4 py-2.5">
                    <dt class="text-[11px] font-medium text-muted-foreground">{{ metric.label }}</dt>
                    <dd class="mt-0.5 text-lg font-bold tabular-nums text-foreground">{{ metric.value }}</dd>
                </div>
            </dl>

            <div class="space-y-3 border-b border-border px-4 py-3">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="overflow-x-auto pb-0.5">
                    <Tabs :model-value="status" @update:model-value="selectStatus">
                        <TabsList aria-label="État des analyses">
                            <TabsTrigger v-for="tab in statusTabs" :key="tab.value" :value="tab.value">
                                {{ tab.label }}<span class="text-xs tabular-nums text-muted-foreground">{{ tab.count }}</span>
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Tabs :model-value="viewMode" @update:model-value="setViewMode">
                            <TabsList aria-label="Mode d’affichage">
                                <TabsTrigger value="list"><List class="h-4 w-4" />Liste</TabsTrigger>
                                <TabsTrigger value="grid"><LayoutGrid class="h-4 w-4" />Grille</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <Button v-if="expandableRoots.length" type="button" variant="ghost" size="sm" @click="toggleAllVisible">
                            <ChevronsUpDown class="h-4 w-4" />{{ allVisibleExpanded ? 'Tout réduire' : 'Tout développer' }}
                        </Button>
                    </div>
                </div>

                <form class="flex flex-col gap-2 lg:flex-row lg:items-center" role="search" @submit.prevent="applyFilters">
                    <div class="relative min-w-0 flex-1 lg:max-w-md">
                        <IconInput v-model="search" :icon="Search" type="search" inputmode="search" placeholder="Code, analyse ou prestation…" aria-label="Rechercher dans le catalogue" class="pe-9" />
                        <button
                            v-if="search"
                            type="button"
                            class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground"
                            aria-label="Effacer la recherche"
                            @click="search = ''"
                        ><X class="h-4 w-4" /></button>
                    </div>
                    <Select
                        :model-value="catalogItem"
                        :options="catalogOptions"
                        :icon="Tags"
                        aria-label="Filtrer par prestation"
                        class="w-full lg:w-72"
                        @update:model-value="selectCatalogItem"
                    />
                    <div class="flex gap-2">
                        <Button type="submit" variant="outline"><Search class="h-4 w-4" />Rechercher</Button>
                        <Button v-if="hasFilters" type="button" variant="ghost" @click="clearFilters"><X class="h-4 w-4" />Effacer</Button>
                    </div>
                </form>
            </div>

            <!-- Vue Grille : une carte par analyse principale, ses enfants restent dans la carte. -->
            <section
                v-if="visibleRootAnalyses.length && viewMode === 'grid'"
                class="grid grid-cols-1 gap-3 bg-muted/20 p-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                aria-label="Catalogue des analyses en grille"
            >
                <article v-for="analysis in visibleRootAnalyses" :key="analysis.uuid" class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm transition-shadow hover:shadow-md">
                    <div class="flex flex-1 flex-col p-4">
                        <header class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                <code class="text-xs font-bold text-primary">{{ analysis.code }}</code>
                                <Badge variant="outline" class="px-1.5 py-0.5 text-[10px]">{{ levelLabel(analysis.level) }}</Badge>
                                <Badge :variant="analysis.is_active ? 'success' : 'secondary'" class="px-1.5 py-0.5 text-[10px]">{{ analysis.is_active ? 'Active' : 'Inactive' }}</Badge>
                            </div>
                            <DropdownMenu v-if="analysisActions(analysis).length" :items="analysisActions(analysis)" @select="onAnalysisAction(analysis, $event)">
                                <template #trigger><Button type="button" variant="ghost" size="icon" :aria-label="`Actions pour ${analysis.designation}`"><MoreHorizontal class="h-4 w-4" /></Button></template>
                            </DropdownMenu>
                        </header>

                        <h3 :class="cn('mt-3 text-base leading-6 text-foreground', analysis.is_bold ? 'font-bold' : 'font-semibold')">{{ analysis.designation }}</h3>
                        <div class="mt-1.5 flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                            <span class="truncate">{{ analysis.catalog_item.name }}</span>
                            <Link v-if="can('catalog.tariffs.view')" :href="tariffsUrl(selectedSiteCode, analysis.catalog_item.code)" class="shrink-0 font-medium text-primary underline-offset-4 hover:underline">Tarif</Link>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-3 text-xs">
                            <div class="min-w-0">
                                <dt class="text-muted-foreground">Résultat</dt>
                                <dd class="mt-1 truncate font-medium text-foreground">{{ typeLabel(analysis.result_type) }}<template v-if="analysis.unit"> · {{ analysis.unit }}</template></dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-muted-foreground">Références</dt>
                                <dd class="mt-1 truncate font-medium text-foreground">{{ referenceLines(analysis).length ? `${referenceLines(analysis).length} configurée(s)` : 'Non configurées' }}</dd>
                            </div>
                            <div v-if="analysis.exam_category" class="col-span-2 min-w-0">
                                <dt class="text-muted-foreground">Examen</dt>
                                <dd class="mt-1 truncate font-medium text-foreground">{{ analysis.exam_category }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div v-if="analysis.children.length" class="border-t border-border">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-start text-xs font-semibold text-foreground transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                            :aria-expanded="isExpanded(analysis.uuid)"
                            @click="toggleGroup(analysis.uuid)"
                        >
                            <span>{{ descendantsOf(analysis).length }} sous-analyse{{ descendantsOf(analysis).length > 1 ? 's' : '' }}</span>
                            <ChevronRight :class="cn('h-4 w-4 text-muted-foreground transition-transform', isExpanded(analysis.uuid) && 'rotate-90')" />
                        </button>
                        <ul v-if="isExpanded(analysis.uuid)" class="divide-y divide-border border-t border-border bg-muted/20">
                            <li v-for="child in descendantsOf(analysis)" :key="child.uuid" class="flex items-start gap-2 px-3 py-2.5" :style="{ paddingInlineStart: `${0.75 + child.tree_depth * 0.65}rem` }">
                                <ChevronRight class="mt-1 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5"><code class="text-[10px] font-bold text-primary">{{ child.code }}</code><Badge variant="outline" class="px-1.5 py-0.5 text-[9px]">{{ levelLabel(child.level) }}</Badge></div>
                                    <p :class="cn('mt-1 text-xs text-foreground', child.is_bold ? 'font-bold' : 'font-semibold')">{{ child.designation }}</p>
                                    <p class="mt-0.5 truncate text-[11px] text-muted-foreground">{{ typeLabel(child.result_type) }}<template v-if="child.unit"> · {{ child.unit }}</template></p>
                                </div>
                                <DropdownMenu v-if="analysisActions(child).length" :items="analysisActions(child)" @select="onAnalysisAction(child, $event)">
                                    <template #trigger><Button type="button" variant="ghost" size="icon" :aria-label="`Actions pour ${child.designation}`"><MoreHorizontal class="h-4 w-4" /></Button></template>
                                </DropdownMenu>
                            </li>
                        </ul>
                    </div>
                </article>
            </section>

            <!-- Vue Liste mobile : groupes compacts, sans tableau horizontal. -->
            <ul v-if="visibleRootAnalyses.length && viewMode === 'list'" class="space-y-2.5 p-3 lg:hidden">
                <li v-for="analysis in visibleRootAnalyses" :key="analysis.uuid" class="overflow-hidden rounded-xl border border-border bg-card">
                    <div class="space-y-3 p-3">
                        <div class="flex items-start gap-2.5">
                            <button
                                v-if="analysis.children.length"
                                type="button"
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary transition-colors hover:bg-primary/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-expanded="isExpanded(analysis.uuid)"
                                :aria-label="`${isExpanded(analysis.uuid) ? 'Réduire' : 'Développer'} ${analysis.designation}`"
                                @click="toggleGroup(analysis.uuid)"
                            ><ChevronRight :class="cn('h-4 w-4 transition-transform', isExpanded(analysis.uuid) && 'rotate-90')" /></button>
                            <span v-else class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><FlaskConical class="h-4 w-4" /></span>
                            <button v-if="analysis.children.length" type="button" class="min-w-0 flex-1 text-start" :aria-expanded="isExpanded(analysis.uuid)" @click="toggleGroup(analysis.uuid)">
                                <span class="flex flex-wrap items-center gap-1.5"><code class="text-xs font-bold text-primary">{{ analysis.code }}</code><Badge variant="outline">{{ levelLabel(analysis.level) }}</Badge><Badge variant="secondary">{{ descendantsOf(analysis).length }} sous-analyse{{ descendantsOf(analysis).length > 1 ? 's' : '' }}</Badge></span>
                                <span :class="cn('mt-1 block text-sm text-foreground', analysis.is_bold ? 'font-bold' : 'font-semibold')">{{ analysis.designation }}</span>
                            </button>
                            <div v-else class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5"><code class="text-xs font-bold text-primary">{{ analysis.code }}</code><Badge variant="outline">{{ levelLabel(analysis.level) }}</Badge></div>
                                <p :class="cn('mt-1 text-sm text-foreground', analysis.is_bold ? 'font-bold' : 'font-semibold')">{{ analysis.designation }}</p>
                            </div>
                            <DropdownMenu v-if="analysisActions(analysis).length" :items="analysisActions(analysis)" @select="onAnalysisAction(analysis, $event)">
                                <template #trigger><Button type="button" variant="ghost" size="icon" :aria-label="`Actions pour ${analysis.designation}`"><MoreHorizontal class="h-4 w-4" /></Button></template>
                            </DropdownMenu>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                            <div><dt class="text-muted-foreground">Prestation</dt><dd class="mt-0.5 truncate font-medium text-foreground">{{ analysis.catalog_item.name }}</dd></div>
                            <div><dt class="text-muted-foreground">Résultat</dt><dd class="mt-0.5 font-medium text-foreground">{{ typeLabel(analysis.result_type) }}<template v-if="analysis.unit"> · {{ analysis.unit }}</template></dd></div>
                            <div><dt class="text-muted-foreground">Références</dt><dd class="mt-0.5 font-medium text-foreground">{{ referenceLines(analysis).length ? `${referenceLines(analysis).length} configurée(s)` : 'Non configurées' }}</dd></div>
                            <div><dt class="text-muted-foreground">État</dt><dd class="mt-0.5"><Badge :variant="analysis.is_active ? 'success' : 'secondary'">{{ analysis.is_active ? 'Active' : 'Inactive' }}</Badge></dd></div>
                        </dl>
                    </div>

                    <ul v-if="analysis.children.length && isExpanded(analysis.uuid)" class="divide-y divide-border border-t border-border bg-muted/20">
                        <li v-for="child in descendantsOf(analysis)" :key="child.uuid" class="flex items-start gap-2.5 px-3 py-3" :style="{ paddingInlineStart: `${0.75 + child.tree_depth * 0.75}rem` }">
                            <span class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center text-muted-foreground"><ChevronRight class="h-3.5 w-3.5" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5"><code class="text-[11px] font-bold text-primary">{{ child.code }}</code><Badge variant="outline" class="px-1.5 py-0.5 text-[10px]">{{ levelLabel(child.level) }}</Badge></div>
                                <p :class="cn('mt-1 text-sm text-foreground', child.is_bold ? 'font-bold' : 'font-semibold')">{{ child.designation }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ typeLabel(child.result_type) }}<template v-if="child.unit"> · {{ child.unit }}</template><template v-if="referenceLines(child).length"> · {{ referenceLines(child).length }} référence(s)</template></p>
                            </div>
                            <Badge :variant="child.is_active ? 'success' : 'secondary'" class="shrink-0">{{ child.is_active ? 'Active' : 'Inactive' }}</Badge>
                            <DropdownMenu v-if="analysisActions(child).length" :items="analysisActions(child)" @select="onAnalysisAction(child, $event)">
                                <template #trigger><Button type="button" variant="ghost" size="icon" :aria-label="`Actions pour ${child.designation}`"><MoreHorizontal class="h-4 w-4" /></Button></template>
                            </DropdownMenu>
                        </li>
                    </ul>
                </li>
            </ul>

            <div v-if="visibleRootAnalyses.length && viewMode === 'list'" class="hidden overflow-x-auto lg:block">
                <table class="w-full min-w-[67rem] border-collapse text-sm">
                    <caption class="sr-only">Catalogue des analyses de {{ selectedSite.site.name }}</caption>
                    <thead class="border-b border-border bg-muted/35 text-xs text-muted-foreground">
                        <tr>
                            <th class="h-10 px-4 text-start font-medium">Analyse</th>
                            <th class="h-10 px-3 text-start font-medium">Prestation liée</th>
                            <th class="h-10 px-3 text-start font-medium">Résultat</th>
                            <th class="h-10 px-3 text-start font-medium">Références</th>
                            <th class="h-10 px-3 text-start font-medium">État</th>
                            <th class="h-10 px-4 text-end font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody v-for="root in visibleRootAnalyses" :key="root.uuid" class="border-b border-border last:border-b-0">
                        <tr
                            v-for="analysis in [root, ...(isExpanded(root.uuid) ? descendantsOf(root) : [])]"
                            :key="analysis.uuid"
                            :class="cn('transition-colors hover:bg-muted/25', analysis.tree_depth > 0 && 'bg-muted/15')"
                        >
                            <td class="max-w-md px-4 py-3 align-top">
                                <div class="flex items-start gap-2" :style="{ paddingInlineStart: `${analysis.tree_depth * 1.15}rem` }">
                                    <button
                                        v-if="analysis.uuid === root.uuid && root.children.length"
                                        type="button"
                                        class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-md text-primary transition-colors hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        :aria-expanded="isExpanded(root.uuid)"
                                        :aria-label="`${isExpanded(root.uuid) ? 'Réduire' : 'Développer'} ${root.designation}`"
                                        @click="toggleGroup(root.uuid)"
                                    ><ChevronRight :class="cn('h-4 w-4 transition-transform', isExpanded(root.uuid) && 'rotate-90')" /></button>
                                    <span v-else-if="analysis.tree_depth > 0" class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center text-muted-foreground"><ChevronRight class="h-3.5 w-3.5" /></span>
                                    <div class="min-w-0 flex-1">
                                        <button v-if="analysis.uuid === root.uuid && root.children.length" type="button" class="w-full text-start" :aria-expanded="isExpanded(root.uuid)" @click="toggleGroup(root.uuid)">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <code class="text-xs font-bold text-primary">{{ analysis.code }}</code>
                                                <Badge variant="outline" class="px-1.5 py-0.5 text-[10px]">{{ levelLabel(analysis.level) }}</Badge>
                                                <Badge variant="secondary" class="px-1.5 py-0.5 text-[10px]">{{ descendantsOf(root).length }} sous-analyse{{ descendantsOf(root).length > 1 ? 's' : '' }}</Badge>
                                                <Badge v-if="analysis.source_system" variant="secondary" class="px-1.5 py-0.5 text-[10px]" :title="`Type historique : ${analysis.source_metadata?.type_name ?? 'non renseigné'}`">Historique</Badge>
                                                <Bold v-if="analysis.is_bold" class="h-3.5 w-3.5 text-muted-foreground" aria-label="Gras à l’impression" />
                                            </span>
                                            <span :class="cn('mt-1 block text-sm text-foreground', analysis.is_bold ? 'font-bold' : 'font-semibold')">{{ analysis.designation }}</span>
                                        </button>
                                        <template v-else>
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <code class="text-xs font-bold text-primary">{{ analysis.code }}</code>
                                                <Badge variant="outline" class="px-1.5 py-0.5 text-[10px]">{{ levelLabel(analysis.level) }}</Badge>
                                                <Badge v-if="analysis.source_system" variant="secondary" class="px-1.5 py-0.5 text-[10px]" :title="`Type historique : ${analysis.source_metadata?.type_name ?? 'non renseigné'}`">Historique</Badge>
                                                <Bold v-if="analysis.is_bold" class="h-3.5 w-3.5 text-muted-foreground" aria-label="Gras à l’impression" />
                                            </div>
                                            <p :class="cn('mt-1 text-sm text-foreground', analysis.is_bold ? 'font-bold' : 'font-semibold')">{{ analysis.designation }}</p>
                                            <p v-if="analysis.tree_depth > 0" class="mt-0.5 truncate text-[11px] text-muted-foreground" :title="ancestorPath(analysis)">Sous {{ ancestorPath(analysis) }}</p>
                                        </template>
                                    </div>
                                </div>
                            </td>
                            <td class="max-w-xs px-3 py-3 align-top">
                                <p class="truncate text-xs font-semibold text-foreground">{{ analysis.catalog_item.name }}</p>
                                <div class="mt-1 flex items-center gap-2 text-[11px] text-muted-foreground">
                                    <code>{{ analysis.catalog_item.code }}</code>
                                    <Link v-if="can('catalog.tariffs.view')" :href="tariffsUrl(selectedSiteCode, analysis.catalog_item.code)" class="font-medium text-primary underline-offset-4 hover:underline">Voir le tarif</Link>
                                </div>
                            </td>
                            <td class="px-3 py-3 align-top text-xs text-muted-foreground">
                                <p class="font-medium text-foreground">{{ typeLabel(analysis.result_type) }}<template v-if="analysis.unit"> · {{ analysis.unit }}</template></p>
                                <p v-if="analysis.exam_category" class="mt-1">{{ analysis.exam_category }}</p>
                                <p v-if="analysis.source_metadata?.type_label" class="mt-1 text-[11px]">Ancien : {{ analysis.source_metadata.type_label }}</p>
                                <p v-if="analysis.predefined_values?.length" class="mt-1 max-w-52 truncate text-[11px]" :title="analysis.predefined_values.join(' · ')">{{ analysis.predefined_values.join(' · ') }}</p>
                            </td>
                            <td class="px-3 py-3 align-top text-xs leading-5 text-muted-foreground">
                                <template v-if="referenceLines(analysis).length">
                                    <p v-for="([label, value]) in referenceLines(analysis)" :key="label"><span class="font-medium text-foreground">{{ label }}</span> {{ value }}</p>
                                </template>
                                <span v-else>Non configurées</span>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <Badge :variant="analysis.is_active ? 'success' : 'secondary'">{{ analysis.is_active ? 'Active' : 'Inactive' }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-end align-top">
                                <DropdownMenu v-if="analysisActions(analysis).length" :items="analysisActions(analysis)" @select="onAnalysisAction(analysis, $event)">
                                    <template #trigger><Button type="button" variant="ghost" size="icon" :aria-label="`Actions pour ${analysis.designation}`"><MoreHorizontal class="h-4 w-4" /></Button></template>
                                </DropdownMenu>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="! visibleRootAnalyses.length" class="flex flex-col items-center gap-2 px-6 py-12 text-center">
                <span class="grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground"><Activity class="h-5 w-5" /></span>
                <p class="text-sm font-semibold text-foreground">Aucune analyse trouvée</p>
                <p class="max-w-lg text-sm leading-6 text-muted-foreground">Aucune ligne ne correspond à ces filtres sur {{ selectedSite.site.name }}.</p>
                <Button v-if="hasFilters" type="button" size="sm" variant="outline" @click="clearFilters"><X class="h-4 w-4" />Effacer les filtres</Button>
            </div>

            <footer class="flex flex-col gap-3 border-t border-border px-4 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                <span><strong class="font-medium text-foreground">{{ rangeStart }}–{{ rangeEnd }}</strong> sur {{ analysisTree.length }} analyses principales · {{ orderedAnalyses.length }} lignes au total · via l’API de {{ selectedSite.site.name }}</span>
                <div v-if="pageCount > 1" class="flex items-center gap-2">
                    <Button type="button" size="sm" variant="outline" :disabled="pageNumber === 1" @click="goToPage(pageNumber - 1)"><ChevronLeft class="h-4 w-4" />Précédent</Button>
                    <span class="min-w-20 text-center tabular-nums">Page {{ pageNumber }} / {{ pageCount }}</span>
                    <Button type="button" size="sm" variant="outline" :disabled="pageNumber === pageCount" @click="goToPage(pageNumber + 1)">Suivant<ChevronRight class="h-4 w-4" /></Button>
                </div>
            </footer>
        </Card>

        <Dialog
            :open="importOpen"
            title="Importer le catalogue des analyses"
            description="Le fichier est contrôlé intégralement avant écriture. Une ligne invalide annule tout l’import."
            :dismissible="! importForm.processing"
            @update:open="(open) => open ? (importOpen = true) : closeImport()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Upload class="h-5 w-5" /></span>
            </template>

            <form id="analysis-import-form" class="space-y-4" @submit.prevent="submitImport">
                <FormField label="Site destinataire" required :icon="Server">
                    <Select v-model="importForm.site_code" :options="reachableSiteOptions" placeholder="Choisir une clinique" class="w-full" />
                </FormField>
                <FormField label="Fichier Excel" required hint=".xlsx, 1 000 lignes maximum" :error="importError" :icon="FileSpreadsheet">
                    <Input :key="importInputKey" type="file" accept=".xlsx" required class="cursor-pointer file:me-3 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground" @change="importForm.file = $event.target.files[0] ?? null" />
                </FormField>
                <p class="rounded-lg border border-border bg-muted/35 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
                    Besoin de la structure attendue ?
                    <a href="/super-admin/analyses/import-template" class="font-semibold text-primary underline-offset-4 hover:underline">Télécharger le modèle Excel</a>.
                </p>
            </form>

            <template #footer>
                <Button type="button" variant="outline" :disabled="importForm.processing" @click="closeImport">Annuler</Button>
                <Button type="submit" form="analysis-import-form" :disabled="importForm.processing || ! importForm.file || ! importForm.site_code">
                    <Upload class="h-4 w-4" />{{ importForm.processing ? 'Import en cours…' : 'Importer sur le site' }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
