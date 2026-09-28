<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Building2,
    ChevronDown,
    CircleCheck,
    CircleHelp,
    Copy,
    Download,
    FileSpreadsheet,
    History,
    ListChecks,
    ListTree,
    MoreHorizontal,
    Pencil,
    Percent,
    Plus,
    RotateCcw,
    Search,
    Server,
    Upload,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import SettingsSiteSwitcher from '@/Components/Settings/SettingsSiteSwitcher.vue';
import TariffCategoryNav from '@/Components/SuperAdmin/Tariffs/TariffCategoryNav.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import FormError from '@/Components/UI/FormError.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import { categoryIcon } from '@/utilities/tariffCategoryIcons';
import {
    CATEGORY_VIEWS,
    LABORATORY,
    analysesUrl,
    catalogAttention,
    catalogCategories,
    catalogGroupKey,
    categoryForSearch,
    disciplineKey,
    disciplineLabel,
    duplicateNames,
    laboratoryDisciplines,
    matchesAttention,
    matchesSearch,
} from '@/utilities/catalogGroups';

/**
 * Tarifs & mutuelles d'un site (ADR-044, ADR-045, ADR-047). Chaque catégorie
 * de désignations — un domaine, l'Imagerie par famille (ADR-106) — est un
 * espace à part, choisi dans la colonne de gauche ; les mutuelles en sont un
 * autre. Tout passe par l'API du site choisi : l'écran ne décide rien, chaque
 * écriture est revérifiée et auditée sur le site destinataire.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    selectedSiteCode: { type: String, default: null },
});

const { can } = usePermissions();
const ORGANIZATIONS = 'ORGANIZATIONS';
const SELECTION_LIMIT = 100;

const firstOnlineSite = props.sites.find((site) => site.ok)?.site.code;
const requestedSite = props.sites.find((site) => site.site.code === props.selectedSiteCode)?.site.code;
const selectedSiteCode = ref(requestedSite ?? firstOnlineSite ?? props.sites[0]?.site.code);
// L'adresse garde ce que l'on regarde (`?site=A&module=LABORATORY&q=NFS`,
// `?section=mutuelles`) : une actualisation ne perd rien. `usePage().url`
// existe aussi au rendu serveur — aucune lecture du navigateur ici.
const initialQuery = new URL(usePage().url, 'http://rivo.local').searchParams;
/** Une recherche venue d'ailleurs (le catalogue des analyses) ouvre la catégorie de son code. */
const initialSearch = initialQuery.get('q') ?? '';
const requestedCategory = ref(initialQuery.get('module') ?? '');
const workspaceView = ref(initialQuery.get('section') === 'mutuelles' && can('mutual_organizations.view') ? ORGANIZATIONS : 'TARIFFS');
const search = ref(initialSearch);
const itemView = ref('ACTIVE');
const typeFilter = ref('');
/** Au Laboratoire : la discipline, lue sur l'analyse racine (ADR-063). */
const disciplineFilter = ref('');
/** Un point à vérifier dans la catégorie : STAFF, DUPLICATES, LAB_NO_ANALYSIS. */
const attentionFilter = ref('');
const organizationSearch = ref('');
const organizationView = ref('ACTIVE');
const confirmation = ref(null);
const organizationModalOpen = ref(false);
const editingOrganization = ref(null);
const archivingOrganization = ref(null);
const selectedItemUuids = ref(new Set());
const selectedOrganizationUuids = ref(new Set());
const bulkAction = ref(null);
const importOpen = ref(false);

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const siteData = computed(() => selectedSite.value?.data ?? {});
const summary = computed(() => siteData.value.summary ?? {});
const options = computed(() => siteData.value.options ?? {
    types: [], modules: [], imaging_modalities: [], routing_modes: [], tariff_categories: [], staff_coverage_policies: [],
});
const allItems = computed(() => siteData.value.items ?? []);
/** Sans le droit de voir les tarifs, le site ne les sert pas : on ne compte rien « sans tarif » (ADR-102). */
const tariffsVisible = computed(() => summary.value.without_standard_tariff !== null && summary.value.without_standard_tariff !== undefined);
const canManageTariffs = computed(() => can('catalog.tariffs.create') || can('catalog.tariffs.update'));

// ------------------------------------------------------------------------
// La catégorie ouverte
// ------------------------------------------------------------------------
const categories = computed(() => catalogCategories(allItems.value, options.value.modules));
const activeCategoryKey = computed(() => {
    const keys = categories.value.map((category) => category.key);
    if (keys.includes(requestedCategory.value)) return requestedCategory.value;

    const fromSearch = categoryForSearch(allItems.value, initialSearch);

    return fromSearch && keys.includes(fromSearch) ? fromSearch : (keys[0] ?? '');
});
const activeCategory = computed(() => categories.value.find((category) => category.key === activeCategoryKey.value) ?? null);
const navValue = computed(() => (workspaceView.value === ORGANIZATIONS ? ORGANIZATIONS : activeCategoryKey.value));
const categoryItems = computed(() => allItems.value.filter((item) => catalogGroupKey(item) === activeCategoryKey.value));
const viewItems = computed(() => categoryItems.value.filter(CATEGORY_VIEWS[itemView.value] ?? CATEGORY_VIEWS.ACTIVE));
/** Les doublons se lisent dans la catégorie : deux « Glycémie » du même Laboratoire. */
const duplicates = computed(() => duplicateNames(categoryItems.value));

const viewTabs = computed(() => {
    const category = activeCategory.value ?? {};

    return [
        { value: 'ACTIVE', label: 'Actives', count: category.active ?? 0 },
        ...(tariffsVisible.value ? [
            { value: 'MISSING_STANDARD', label: 'Sans tarif standard', count: category.missingStandard ?? 0 },
            { value: 'MISSING_MUTUAL', label: 'Sans tarif mutuelle', count: category.missingMutual ?? 0 },
        ] : []),
        { value: 'ARCHIVED', label: 'Archivées', count: category.archived ?? 0 },
    ];
});

const disciplineOptions = computed(() => {
    if (activeCategory.value?.module !== LABORATORY) return [];
    const disciplines = laboratoryDisciplines(viewItems.value);
    if (disciplines.length < 2) return [];

    return [
        { value: '', label: 'Toutes les disciplines' },
        ...disciplines.map((discipline) => ({ value: discipline.key, label: `${discipline.label} · ${discipline.count}` })),
    ];
});
const typeOptions = computed(() => {
    const present = new Set(categoryItems.value.map((item) => item.type));
    if (present.size < 2) return [];

    return [
        { value: '', label: 'Tous les types' },
        ...options.value.types.filter((type) => present.has(type.value)).map((type) => ({ value: type.value, label: type.label })),
    ];
});
/** Ce qui reste à vérifier dans cette catégorie ; chaque point est un filtre, rien n'est corrigé à la place du Super Admin. */
const attentionOptions = computed(() => {
    const counts = catalogAttention(categoryItems.value);
    const points = [
        { value: 'STAFF', count: counts.staffUnclassified, label: 'Sans politique Personnel' },
        { value: 'DUPLICATES', count: counts.duplicates, label: 'Nom en double' },
        { value: 'LAB_NO_ANALYSIS', count: counts.laboratoryWithoutAnalysis, label: 'Sans analyse technique' },
    ].filter((point) => point.count > 0);
    if (! points.length) return [];

    return [
        { value: '', label: 'Tous les points' },
        ...points.map((point) => ({ value: point.value, label: `${point.label} · ${point.count}` })),
    ];
});

const items = computed(() => viewItems.value.filter((item) => matchesSearch(item, search.value)
    && (! typeFilter.value || item.type === typeFilter.value)
    && (! disciplineFilter.value || disciplineKey(item) === disciplineFilter.value)
    && matchesAttention(item, attentionFilter.value, duplicates.value)));
const hasItemFilters = computed(() => Boolean(search.value.trim() || typeFilter.value || disciplineFilter.value || attentionFilter.value));
/** Une recherche trouve aussi dans les autres catégories : on le dit, sans les mélanger. */
const otherMatches = computed(() => {
    if (! search.value.trim()) return [];
    const matcher = CATEGORY_VIEWS[itemView.value] ?? CATEGORY_VIEWS.ACTIVE;
    const counts = new Map();
    allItems.value.forEach((item) => {
        const key = catalogGroupKey(item);
        if (key === activeCategoryKey.value || ! matcher(item) || ! matchesSearch(item, search.value)) return;
        counts.set(key, (counts.get(key) ?? 0) + 1);
    });

    return categories.value.filter((category) => counts.has(category.key))
        .map((category) => ({ key: category.key, label: category.label, count: counts.get(category.key) }));
});

const resetItemFilters = () => {
    search.value = '';
    typeFilter.value = '';
    disciplineFilter.value = '';
    attentionFilter.value = '';
};
const chooseCategory = (key, { keepSearch = false } = {}) => {
    if (key === ORGANIZATIONS) {
        workspaceView.value = ORGANIZATIONS;
        return;
    }
    const kept = keepSearch ? search.value : '';
    workspaceView.value = 'TARIFFS';
    requestedCategory.value = key;
    resetItemFilters();
    search.value = kept;
};

const pricedCount = computed(() => (activeCategory.value ? activeCategory.value.billable - activeCategory.value.missingStandard : 0));
const pricedShare = computed(() => (activeCategory.value?.billable ? Math.round((pricedCount.value / activeCategory.value.billable) * 100) : 0));

// ------------------------------------------------------------------------
// Sélection
// ------------------------------------------------------------------------
const selectedItems = computed(() => items.value.filter((item) => selectedItemUuids.value.has(item.uuid)));
const selectedActiveItems = computed(() => selectedItems.value.filter((item) => ! item.archived));
const selectedArchivedItems = computed(() => selectedItems.value.filter((item) => item.archived));
const itemsHeaderState = computed(() => {
    const chosen = selectedItems.value.length;
    if (! items.value.length || ! chosen) return false;

    return chosen === items.value.length ? true : 'indeterminate';
});
const toggleItemSelection = (uuid) => {
    const next = new Set(selectedItemUuids.value);
    next.has(uuid) ? next.delete(uuid) : next.size < SELECTION_LIMIT && next.add(uuid);
    selectedItemUuids.value = next;
};
const toggleVisibleItemSelection = () => {
    const next = new Set(selectedItemUuids.value);
    if (itemsHeaderState.value === true) items.value.forEach((item) => next.delete(item.uuid));
    else items.value.forEach((item) => { if (next.size < SELECTION_LIMIT) next.add(item.uuid); });
    selectedItemUuids.value = next;
};
const clearItemSelection = () => { selectedItemUuids.value = new Set(); };

// ------------------------------------------------------------------------
// Mutuelles
// ------------------------------------------------------------------------
const organizationsSummary = computed(() => siteData.value.mutual_organizations_summary ?? { active: 0, archived: 0, active_coverages: 0 });
const organizationTabs = computed(() => [
    { value: 'ACTIVE', label: 'Actives', count: organizationsSummary.value.active },
    { value: 'ARCHIVED', label: 'Archivées', count: organizationsSummary.value.archived },
]);
const organizations = computed(() => {
    const needle = organizationSearch.value.trim();

    return (siteData.value.mutual_organizations ?? []).filter((organization) => (organizationView.value === 'ACTIVE' ? organization.active : ! organization.active)
        && matchesSearch({ name: organization.name }, needle));
});
const selectedOrganizations = computed(() => organizations.value.filter((organization) => selectedOrganizationUuids.value.has(organization.uuid)));
const selectedActiveOrganizations = computed(() => selectedOrganizations.value.filter((organization) => organization.active));
const selectedArchivedOrganizations = computed(() => selectedOrganizations.value.filter((organization) => ! organization.active));
const organizationsHeaderState = computed(() => {
    const chosen = selectedOrganizations.value.length;
    if (! organizations.value.length || ! chosen) return false;

    return chosen === organizations.value.length ? true : 'indeterminate';
});
const toggleOrganizationSelection = (uuid) => {
    const next = new Set(selectedOrganizationUuids.value);
    next.has(uuid) ? next.delete(uuid) : next.size < SELECTION_LIMIT && next.add(uuid);
    selectedOrganizationUuids.value = next;
};
const toggleVisibleOrganizationSelection = () => {
    const next = new Set(selectedOrganizationUuids.value);
    if (organizationsHeaderState.value === true) organizations.value.forEach((organization) => next.delete(organization.uuid));
    else organizations.value.forEach((organization) => { if (next.size < SELECTION_LIMIT) next.add(organization.uuid); });
    selectedOrganizationUuids.value = next;
};
const clearOrganizationSelection = () => { selectedOrganizationUuids.value = new Set(); };

// ------------------------------------------------------------------------
// Excel
// ------------------------------------------------------------------------
const exportUrl = ({ organizations: forOrganizations = false, category = null, uuids = [] } = {}) => {
    const base = forOrganizations
        ? '/super-admin/workspaces/tariffs/mutual-organizations/export'
        : '/super-admin/workspaces/tariffs/export';
    const params = new URLSearchParams({ site_code: selectedSiteCode.value });
    if (category) params.set('module', category);
    uuids.forEach((uuid) => params.append('uuids[]', uuid));

    return `${base}?${params.toString()}`;
};
const importTemplateUrl = computed(() => (workspaceView.value === ORGANIZATIONS
    ? '/super-admin/workspaces/tariffs/mutual-organizations/import-template'
    : '/super-admin/workspaces/tariffs/import-template'));
const excelItems = computed(() => {
    const forOrganizations = workspaceView.value === ORGANIZATIONS;
    const exports = forOrganizations
        ? [can('mutual_organizations.export') && { key: 'export-organizations', label: 'Exporter les mutuelles', icon: Download }]
        : [
            can('catalog.tariffs.export') && activeCategory.value && {
                key: 'export-category', label: `Exporter « ${activeCategory.value.label} »`, icon: Download,
                description: 'Les désignations de cette catégorie et leurs deux tarifs.',
            },
            can('catalog.tariffs.export') && { key: 'export-site', label: 'Exporter tout le site', icon: Download },
        ];
    const canImport = forOrganizations ? can('mutual_organizations.import') : can('catalog.tariffs.import');
    const imports = canImport ? [
        { key: 'import', label: forOrganizations ? 'Importer des mutuelles' : 'Importer des tarifs', icon: Upload, description: 'Fichier .xlsx validé en entier avant d’être appliqué.' },
        { key: 'template', label: 'Télécharger le modèle', icon: FileSpreadsheet },
    ] : [];
    const present = exports.filter(Boolean);

    return [...present, ...imports.map((entry, index) => ({ ...entry, separatorBefore: index === 0 && present.length > 0 }))];
});
const onExcel = (key) => {
    if (key === 'import') return openImport();
    const target = {
        'export-category': () => exportUrl({ category: activeCategoryKey.value }),
        'export-site': () => exportUrl(),
        'export-organizations': () => exportUrl({ organizations: true }),
        template: () => importTemplateUrl.value,
    }[key];
    if (target) window.location.assign(target());
};
const selectionExportUrl = computed(() => (workspaceView.value === ORGANIZATIONS
    ? exportUrl({ organizations: true, uuids: [...selectedOrganizationUuids.value] })
    : exportUrl({ uuids: [...selectedItemUuids.value] })));

// ------------------------------------------------------------------------
// Formulaires
// ------------------------------------------------------------------------
const confirmationForm = useForm({ reason: '' });
const organizationForm = useForm({ site_code: selectedSiteCode.value, name: '', coverage_rate: '100.00' });
const organizationArchiveForm = useForm({ reason: '' });
const bulkForm = useForm({ site_code: selectedSiteCode.value, uuids: [], reason: '' });
const importForm = useForm({ site_code: selectedSiteCode.value, file: null });

// Une sélection ne survit ni à un changement de catégorie ni d'onglet : on n'agit jamais sur ce qu'on ne voit pas.
watch([activeCategoryKey, itemView], clearItemSelection);
watch(organizationView, clearOrganizationSelection);
watch(workspaceView, () => {
    importOpen.value = false;
    importForm.clearErrors();
    importForm.file = null;
});

// La catégorie, la section et la recherche suivent l'adresse, sans nouvelle visite.
watch([activeCategoryKey, search, workspaceView], ([category, needle, section]) => {
    const url = new URL(window.location.href);
    if (section === ORGANIZATIONS) {
        url.searchParams.set('section', 'mutuelles');
        url.searchParams.delete('module');
        url.searchParams.delete('q');
    } else {
        url.searchParams.delete('section');
        category ? url.searchParams.set('module', category) : url.searchParams.delete('module');
        needle.trim() ? url.searchParams.set('q', needle.trim()) : url.searchParams.delete('q');
    }
    window.history.replaceState(window.history.state, '', url);
});

const selectSite = (code) => {
    selectedSiteCode.value = code;
    resetItemFilters();
    itemView.value = 'ACTIVE';
    organizationSearch.value = '';
    organizationView.value = 'ACTIVE';
    organizationForm.site_code = code;
    importForm.site_code = code;
    importForm.file = null;
    importForm.clearErrors();
    importOpen.value = false;
    clearItemSelection();
    clearOrganizationSelection();

    const url = new URL(window.location.href);
    url.searchParams.set('site', code);
    window.history.replaceState(window.history.state, '', url);
};

// Créer ou modifier une désignation se fait sur sa page (ADR-044, amendement du 2026-09-28).
const createUrl = computed(() => `/super-admin/workspaces/tariffs/items/create?${new URLSearchParams({
    site: selectedSiteCode.value,
    ...(activeCategoryKey.value ? { module: activeCategoryKey.value } : {}),
}).toString()}`);
/** La fiche d'une désignation ; avec une grille, elle s'ouvre sur ses tarifs, le champ de cette grille prêt. */
const editUrl = (item, grid = null) => `/super-admin/workspaces/tariffs/items/${selectedSiteCode.value}/${item.uuid}/edit${grid ? `?grille=${grid}#tarifs` : ''}`;

const requestArchiveItem = (item) => {
    confirmationForm.reset();
    confirmationForm.clearErrors();
    confirmation.value = { mode: 'item', item };
};

const closeConfirmation = () => {
    if (confirmationForm.processing) return;
    confirmation.value = null;
    confirmationForm.reset();
};

const submitConfirmation = () => confirmationForm.delete(
    `/super-admin/workspaces/tariffs/items/${selectedSiteCode.value}/${confirmation.value.item.uuid}`,
    { preserveScroll: true, onSuccess: closeConfirmation },
);

const restoreItem = (item) => router.post(
    `/super-admin/workspaces/tariffs/items/${selectedSiteCode.value}/${item.uuid}/restore`,
    {},
    { preserveScroll: true },
);

/** Les actions d'une ligne : seulement celles que le compte peut faire. */
const itemActions = (item) => [
    can('catalog.items.update') && { key: 'edit', label: 'Modifier', icon: Pencil },
    item.billable && canManageTariffs.value && { key: 'tariffs', label: 'Tarifs et historique', icon: History },
    can('catalog.items.delete') && { key: 'archive', label: 'Archiver', icon: Archive, destructive: true, separatorBefore: true },
].filter(Boolean);
const onItemAction = (item, key) => {
    if (key === 'edit') router.visit(editUrl(item));
    if (key === 'tariffs') router.visit(editUrl(item, itemView.value === 'MISSING_MUTUAL' ? 'MUTUAL' : 'STANDARD'));
    if (key === 'archive') requestArchiveItem(item);
};

const openCreateOrganization = () => {
    editingOrganization.value = null;
    organizationForm.reset();
    organizationForm.clearErrors();
    organizationForm.site_code = selectedSiteCode.value;
    organizationForm.coverage_rate = '100.00';
    organizationModalOpen.value = true;
};

const openEditOrganization = (organization) => {
    editingOrganization.value = organization;
    organizationForm.clearErrors();
    organizationForm.site_code = selectedSiteCode.value;
    organizationForm.name = organization.name;
    organizationForm.coverage_rate = organization.coverage_rate ?? '100.00';
    organizationModalOpen.value = true;
};

const closeOrganizationModal = () => {
    if (organizationForm.processing) return;
    organizationModalOpen.value = false;
    editingOrganization.value = null;
    organizationForm.reset();
    organizationForm.clearErrors();
    organizationForm.site_code = selectedSiteCode.value;
    organizationForm.coverage_rate = '100.00';
};

const submitOrganization = () => {
    if (editingOrganization.value) {
        organizationForm.transform((data) => ({ name: data.name, coverage_rate: data.coverage_rate })).put(
            `/super-admin/workspaces/tariffs/mutual-organizations/${selectedSiteCode.value}/${editingOrganization.value.uuid}`,
            { preserveScroll: true, onSuccess: closeOrganizationModal },
        );
        return;
    }

    organizationForm.transform((data) => data).post('/super-admin/workspaces/tariffs/mutual-organizations', {
        preserveScroll: true,
        onSuccess: closeOrganizationModal,
    });
};

const openImport = () => {
    importForm.clearErrors();
    importForm.site_code = selectedSiteCode.value;
    importForm.file = null;
    importOpen.value = true;
};

const closeImport = () => {
    if (importForm.processing) return;
    importOpen.value = false;
};

const submitImport = () => {
    const endpoint = workspaceView.value === ORGANIZATIONS
        ? '/super-admin/workspaces/tariffs/mutual-organizations/import'
        : '/super-admin/workspaces/tariffs/import';

    importForm.post(endpoint, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importOpen.value = false;
            importForm.reset('file');
        },
    });
};

const openArchiveOrganization = (organization) => {
    archivingOrganization.value = organization;
    organizationArchiveForm.reset();
    organizationArchiveForm.clearErrors();
};

const closeArchiveOrganization = () => {
    if (organizationArchiveForm.processing) return;
    archivingOrganization.value = null;
    organizationArchiveForm.reset();
};

const submitArchiveOrganization = () => organizationArchiveForm.delete(
    `/super-admin/workspaces/tariffs/mutual-organizations/${selectedSiteCode.value}/${archivingOrganization.value.uuid}`,
    { preserveScroll: true, onSuccess: closeArchiveOrganization },
);

const restoreOrganization = (organization) => router.post(
    `/super-admin/workspaces/tariffs/mutual-organizations/${selectedSiteCode.value}/${organization.uuid}/restore`,
    {},
    { preserveScroll: true },
);

const organizationActions = () => [
    can('mutual_organizations.update') && { key: 'edit', label: 'Modifier', icon: Pencil },
    can('mutual_organizations.archive') && { key: 'archive', label: 'Archiver', icon: Archive, destructive: true, separatorBefore: true },
].filter(Boolean);
const onOrganizationAction = (organization, key) => {
    if (key === 'edit') openEditOrganization(organization);
    if (key === 'archive') openArchiveOrganization(organization);
};

const openBulkAction = (entity, mode) => {
    const targets = entity === 'ITEMS'
        ? (mode === 'ARCHIVE' ? selectedActiveItems.value : selectedArchivedItems.value)
        : (mode === 'ARCHIVE' ? selectedActiveOrganizations.value : selectedArchivedOrganizations.value);
    if (! targets.length) return;

    bulkForm.reset();
    bulkForm.clearErrors();
    bulkForm.site_code = selectedSiteCode.value;
    bulkForm.uuids = targets.map((target) => target.uuid);
    bulkAction.value = { entity, mode, count: targets.length };
};

const closeBulkAction = () => {
    if (bulkForm.processing) return;
    bulkAction.value = null;
    bulkForm.reset();
};

const submitBulkAction = () => {
    const resource = bulkAction.value.entity === 'ITEMS' ? 'items' : 'mutual-organizations';
    const operation = bulkAction.value.mode === 'ARCHIVE' ? 'archive' : 'restore';

    bulkForm.post(`/super-admin/workspaces/tariffs/${resource}/bulk/${operation}`, {
        preserveScroll: true,
        onSuccess: () => {
            const entity = bulkAction.value.entity;
            closeBulkAction();
            entity === 'ITEMS' ? clearItemSelection() : clearOrganizationSelection();
        },
    });
};

const formatDateTime = (value) => (value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—');
const formatRate = (value) => `${Number(value ?? 0).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`;
const patientRatePreview = computed(() => formatRate(Math.max(0, 100 - Number(organizationForm.coverage_rate || 0))));
const plural = (count, singular, pluralForm) => `${count} ${count > 1 ? pluralForm : singular}`;
/** Les autres désignations au même nom, pour le survol du repère « Doublon ». */
const duplicateTitle = (item) => `Même nom que ${duplicates.value.get(item.uuid)?.map((other) => other.code).join(', ')}. Cliquer pour les afficher ensemble.`;

const TH = 'h-10 whitespace-nowrap px-3 text-start align-middle text-xs font-medium text-muted-foreground';
const TD = 'px-3 py-2.5 align-middle';
/** Un repère discret sur une ligne : jamais une couleur qui crie, le mot suffit. */
const FLAG = 'inline-flex items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground';
</script>

<template>
    <Head title="Tarifs & mutuelles" />

    <div class="w-full space-y-6">
        <PageHeader
            compact
            tone="slate"
            title="Tarifs & mutuelles"
            description="Les désignations de chaque clinique, leurs deux tarifs et les mutuelles. Chaque modification part par l’API du site choisi et y est auditée."
            :icon="ListChecks"
        >
            <template #actions>
                <DropdownMenu v-if="excelItems.length && selectedSite?.ok" :items="excelItems" @select="onExcel">
                    <template #trigger>
                        <Button type="button" variant="outline"><FileSpreadsheet class="h-4 w-4" />Excel<ChevronDown class="h-4 w-4 text-muted-foreground" /></Button>
                    </template>
                </DropdownMenu>
            </template>
        </PageHeader>

        <SettingsSiteSwitcher v-if="sites.length" :targets="sites" :model-value="selectedSiteCode" label="Site affiché" @update:model-value="selectSite" />

        <Card v-if="! selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
            <span class="grid h-11 w-11 place-items-center rounded-lg border border-border text-muted-foreground"><Server class="h-5 w-5" /></span>
            <h2 class="mt-3 text-sm font-semibold text-foreground">API indisponible pour {{ selectedSite?.site.name ?? 'ce site' }}</h2>
            <p class="mt-1 max-w-xl text-sm text-muted-foreground">{{ selectedSite?.message }}</p>
            <p class="mt-3 text-xs text-muted-foreground">Les autres sites restent utilisables ; aucune base clinique n’est lue directement.</p>
        </Card>

        <div v-else class="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)]">
            <aside class="lg:sticky lg:top-20 lg:self-start">
                <TariffCategoryNav
                    :categories="categories"
                    :model-value="navValue"
                    :organizations-count="can('mutual_organizations.view') ? organizationsSummary.active : null"
                    @update:model-value="chooseCategory"
                />
            </aside>

            <!-- ------------------------------------------------------------ -->
            <!-- Une catégorie de désignations, et elle seule                  -->
            <!-- ------------------------------------------------------------ -->
            <Card v-if="workspaceView !== 'ORGANIZATIONS' && activeCategory" class="min-w-0 overflow-hidden">
                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-foreground">
                            <component :is="categoryIcon(activeCategory.key)" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold leading-7 text-foreground">{{ activeCategory.label }}</h2>
                            <p class="text-sm text-muted-foreground">
                                {{ plural(activeCategory.active, 'désignation en service', 'désignations en service') }}<template v-if="activeCategory.archived"> · {{ plural(activeCategory.archived, 'archivée', 'archivées') }}</template>
                            </p>
                            <div v-if="tariffsVisible && activeCategory.billable" class="mt-2.5 flex items-center gap-3" :title="`${pricedCount} sur ${activeCategory.billable} facturables ont un tarif sans mutuelle`">
                                <div class="h-1.5 w-40 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="pricedShare" aria-valuemin="0" aria-valuemax="100" aria-label="Désignations tarifées">
                                    <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${pricedShare}%` }" />
                                </div>
                                <span class="text-xs tabular-nums text-muted-foreground">{{ pricedCount }} / {{ activeCategory.billable }} tarifées</span>
                            </div>
                        </div>
                    </div>
                    <Button v-if="can('catalog.items.create')" :as="Link" :href="createUrl" class="shrink-0">
                        <Plus class="h-4 w-4" />Nouvelle désignation
                    </Button>
                </div>

                <p v-if="activeCategory.unclassified" class="mx-5 mb-4 flex items-start gap-2 rounded-lg border border-border px-3.5 py-2.5 text-sm text-muted-foreground">
                    <CircleHelp class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Ces examens n’ont pas encore de famille : ils n’apparaissent ni dans l’onglet Échographie ni dans l’onglet ECG des demandes d’examens. Modifiez-les pour la choisir — elle n’est jamais déduite du code.</span>
                </p>

                <!-- Les onglets de la catégorie, puis, dessous, ce qui affine la liste -->
                <div class="space-y-3 border-t border-border px-5 py-3">
                    <div class="-mx-1 overflow-x-auto px-1 pb-0.5">
                        <Tabs v-model="itemView">
                            <TabsList aria-label="Désignations affichées">
                                <TabsTrigger v-for="tab in viewTabs" :key="tab.value" :value="tab.value">
                                    {{ tab.label }}<span class="text-xs tabular-nums text-muted-foreground">{{ tab.count }}</span>
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <div class="relative w-full sm:min-w-[12rem] sm:max-w-sm sm:flex-1">
                            <IconInput v-model="search" :icon="Search" type="text" inputmode="search" placeholder="Code ou désignation" aria-label="Rechercher dans la catégorie" class="pe-9" />
                            <button v-if="search" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="search = ''">
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <Select v-if="disciplineOptions.length" v-model="disciplineFilter" :options="disciplineOptions" aria-label="Discipline" class="w-full sm:w-48" />
                        <Select v-if="typeOptions.length" v-model="typeFilter" :options="typeOptions" aria-label="Type" class="w-full sm:w-44" />
                        <Select v-if="attentionOptions.length" v-model="attentionFilter" :options="attentionOptions" aria-label="Points à vérifier" class="w-full sm:w-52" />
                        <Button v-if="hasItemFilters" type="button" variant="ghost" size="sm" @click="resetItemFilters"><X class="h-4 w-4" />Effacer</Button>
                    </div>
                </div>

                <p v-if="otherMatches.length" class="flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-border px-5 py-2 text-xs text-muted-foreground">
                    <span>Aussi trouvé dans</span>
                    <template v-for="(match, index) in otherMatches" :key="match.key">
                        <span v-if="index" aria-hidden="true">·</span>
                        <button type="button" class="font-medium text-foreground underline-offset-4 hover:underline" @click="chooseCategory(match.key, { keepSearch: true })">
                            {{ match.label }} ({{ match.count }})
                        </button>
                    </template>
                </p>

                <div v-if="selectedItems.length" class="flex flex-col gap-2 border-t border-border bg-muted/40 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-foreground"><span class="font-semibold tabular-nums">{{ selectedItems.length }}</span> {{ selectedItems.length > 1 ? 'sélectionnées' : 'sélectionnée' }}<span class="text-muted-foreground"> · {{ SELECTION_LIMIT }} au plus</span></p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="can('catalog.tariffs.export')" as="a" :href="selectionExportUrl" size="sm" variant="outline"><Download class="h-4 w-4" />Exporter</Button>
                        <Button v-if="can('catalog.items.delete') && selectedActiveItems.length" type="button" size="sm" variant="outline" @click="openBulkAction('ITEMS', 'ARCHIVE')"><Archive class="h-4 w-4" />Archiver</Button>
                        <Button v-if="can('catalog.items.restore') && selectedArchivedItems.length" type="button" size="sm" variant="outline" @click="openBulkAction('ITEMS', 'RESTORE')"><RotateCcw class="h-4 w-4" />Restaurer</Button>
                        <Button type="button" size="sm" variant="ghost" @click="clearItemSelection">Désélectionner</Button>
                    </div>
                </div>

                <div class="overflow-x-auto border-t border-border">
                    <table class="w-full min-w-[600px] text-sm">
                        <thead class="border-b border-border">
                            <tr>
                                <th :class="cn(TH, 'w-10 ps-5')">
                                    <Checkbox :model-value="itemsHeaderState" :disabled="! items.length" aria-label="Sélectionner les désignations affichées" @update:model-value="toggleVisibleItemSelection" />
                                </th>
                                <th :class="TH">Désignation</th>
                                <th :class="cn(TH, 'hidden 2xl:table-cell')">Réception</th>
                                <th :class="cn(TH, 'text-end')">Sans mutuelle</th>
                                <th :class="cn(TH, 'text-end')">Mutuelle</th>
                                <th :class="cn(TH, 'w-14 pe-5')"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="item in items"
                                :key="item.uuid"
                                :class="cn('transition-colors hover:bg-muted/40', selectedItemUuids.has(item.uuid) && 'bg-muted/50')"
                            >
                                <td :class="cn(TD, 'ps-5')">
                                    <Checkbox :model-value="selectedItemUuids.has(item.uuid)" :aria-label="`Sélectionner ${item.name}`" @update:model-value="toggleItemSelection(item.uuid)" />
                                </td>
                                <td :class="TD">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <Link :href="editUrl(item)" :class="cn('font-medium underline-offset-4 hover:underline', item.archived ? 'text-muted-foreground' : 'text-foreground')" :title="item.description || undefined">{{ item.name }}</Link>
                                        <button v-if="duplicates.has(item.uuid)" type="button" :class="cn(FLAG, 'hover:text-foreground')" :title="duplicateTitle(item)" @click="search = item.name">
                                            <Copy class="h-3 w-3" />Doublon
                                        </button>
                                        <Link
                                            v-if="item.module === 'IMAGING' && ! item.imaging_modality && ! item.archived && can('catalog.items.update')"
                                            :href="editUrl(item)"
                                            :class="cn(FLAG, 'hover:text-foreground')"
                                            title="Choisir sa famille : Échographie ou ECG"
                                        ><CircleHelp class="h-3 w-3" />Famille à choisir</Link>
                                        <span v-if="item.module === 'LABORATORY' && ! item.analyses_count && ! item.archived" :class="FLAG" title="Aucune ligne au catalogue des analyses : le Laboratoire n’a rien où saisir un résultat.">
                                            <ListTree class="h-3 w-3" />Sans analyse
                                        </span>
                                    </div>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                                        <span class="font-mono">{{ item.code }}</span>
                                        <span aria-hidden="true">·</span><span>{{ item.unit }}</span>
                                        <template v-if="item.module === 'LABORATORY' && item.analysis_discipline">
                                            <span aria-hidden="true">·</span><span :title="item.analysis_discipline">{{ disciplineLabel(disciplineKey(item)) }}</span>
                                        </template>
                                        <template v-if="item.module === 'LABORATORY' && item.analyses_count">
                                            <span aria-hidden="true">·</span>
                                            <Link v-if="can('analysis_catalog.view')" :href="analysesUrl(selectedSiteCode, item)" class="underline-offset-4 hover:text-foreground hover:underline" :title="`Ouvrir ses lignes au catalogue des analyses`">{{ plural(item.analyses_count, 'analyse', 'analyses') }}</Link>
                                            <span v-else>{{ plural(item.analyses_count, 'analyse', 'analyses') }}</span>
                                        </template>
                                        <!-- Sous 1536 px, la colonne Réception cède sa place : le parcours se lit ici. -->
                                        <template v-if="item.reception_selectable">
                                            <span class="2xl:hidden" aria-hidden="true">·</span><span class="2xl:hidden">{{ item.reception_routing_label }}</span>
                                        </template>
                                        <template v-if="item.archived && item.archive_reason">
                                            <span aria-hidden="true">·</span><span class="truncate" :title="item.archive_reason">Archivée : {{ item.archive_reason }}</span>
                                        </template>
                                    </p>
                                </td>
                                <td :class="cn(TD, 'hidden whitespace-nowrap text-muted-foreground 2xl:table-cell')">
                                    <span v-if="item.reception_selectable">{{ item.reception_routing_label }}</span>
                                    <span v-else title="Non proposée à la Réception">—</span>
                                </td>
                                <td v-for="grid in ['STANDARD', 'MUTUAL']" :key="grid" :class="cn(TD, 'whitespace-nowrap text-end')">
                                    <template v-for="current in [grid === 'MUTUAL' ? item.current_mutual_tariff : item.current_standard_tariff]" :key="grid">
                                        <Link
                                            v-if="current && ! item.archived && canManageTariffs"
                                            :href="editUrl(item, grid)"
                                            class="rounded-md px-1.5 py-0.5 font-medium tabular-nums text-foreground transition-colors hover:bg-muted"
                                            :title="`Modifier le tarif ${grid === 'MUTUAL' ? 'mutuelle' : 'sans mutuelle'}`"
                                        >{{ formatMoney(current.amount, current.currency) }}</Link>
                                        <span v-else-if="current" class="font-medium tabular-nums text-foreground">{{ formatMoney(current.amount, current.currency) }}</span>
                                        <Button
                                            v-else-if="item.billable && ! item.archived && canManageTariffs && tariffsVisible"
                                            :as="Link"
                                            :href="editUrl(item, grid)"
                                            variant="ghost"
                                            size="xs"
                                            class="-me-1"
                                            :aria-label="`Définir le tarif ${grid === 'MUTUAL' ? 'mutuelle' : 'sans mutuelle'} de ${item.name}`"
                                        ><Plus class="h-3.5 w-3.5" />Définir</Button>
                                        <span v-else class="text-muted-foreground" :title="item.billable ? 'Aucun tarif' : 'Non facturable'">—</span>
                                    </template>
                                </td>
                                <td :class="cn(TD, 'pe-5 text-end')">
                                    <Button v-if="item.archived && can('catalog.items.restore')" type="button" variant="outline" size="xs" @click="restoreItem(item)"><RotateCcw class="h-3.5 w-3.5" />Restaurer</Button>
                                    <DropdownMenu v-else-if="! item.archived && itemActions(item).length" :items="itemActions(item)" content-class="min-w-[12rem]" @select="(key) => onItemAction(item, key)">
                                        <template #trigger>
                                            <Button type="button" variant="ghost" icon size="sm" :aria-label="`Actions pour ${item.name}`"><MoreHorizontal class="h-4 w-4" /></Button>
                                        </template>
                                    </DropdownMenu>
                                </td>
                            </tr>
                            <tr v-if="! items.length">
                                <td colspan="6" class="px-5 py-14 text-center">
                                    <p class="text-sm font-medium text-foreground">{{ hasItemFilters ? 'Aucune désignation ne correspond' : `Aucune désignation dans « ${viewTabs.find((tab) => tab.value === itemView)?.label ?? ''} »` }}</p>
                                    <p class="mt-1 text-sm text-muted-foreground">{{ hasItemFilters ? 'Modifiez la recherche ou les filtres.' : 'Rien à faire ici pour cette catégorie.' }}</p>
                                    <Button v-if="hasItemFilters" type="button" variant="outline" size="sm" class="mt-4" @click="resetItemFilters"><X class="h-4 w-4" />Effacer les filtres</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                    {{ plural(items.length, 'désignation affichée', 'désignations affichées') }} · {{ selectedSite.site.name }}
                </p>
            </Card>

            <Card v-else-if="workspaceView !== 'ORGANIZATIONS'" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <p class="text-sm font-medium text-foreground">Aucune désignation sur {{ selectedSite.site.name }}</p>
                <p class="mt-1 text-sm text-muted-foreground">Créez la première, ou importez un fichier Excel.</p>
                <Button v-if="can('catalog.items.create')" :as="Link" :href="createUrl" class="mt-4"><Plus class="h-4 w-4" />Nouvelle désignation</Button>
            </Card>

            <!-- ------------------------------------------------------------ -->
            <!-- Mutuelles                                                     -->
            <!-- ------------------------------------------------------------ -->
            <Card v-else class="min-w-0 overflow-hidden">
                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-foreground"><Building2 class="h-5 w-5" /></span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold leading-7 text-foreground">Mutuelles</h2>
                            <p class="text-sm text-muted-foreground">
                                {{ plural(organizationsSummary.active, 'mutuelle active', 'mutuelles actives') }} · {{ plural(organizationsSummary.active_coverages, 'couverture en cours', 'couvertures en cours') }}
                            </p>
                            <p class="mt-1.5 max-w-2xl text-xs leading-5 text-muted-foreground">« Sans mutuelle » est le tarif standard et « Avantage Personnel » relève des RH : ce ne sont pas des mutuelles. Les partenaires (ISPSG, médecins extérieurs…) ont leur module Partenaires.</p>
                        </div>
                    </div>
                    <Button v-if="can('mutual_organizations.create')" type="button" class="shrink-0" @click="openCreateOrganization">
                        <Plus class="h-4 w-4" />Nouvelle mutuelle
                    </Button>
                </div>

                <div class="flex flex-col gap-3 border-t border-border px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <Tabs v-model="organizationView">
                        <TabsList aria-label="Mutuelles affichées">
                            <TabsTrigger v-for="tab in organizationTabs" :key="tab.value" :value="tab.value">
                                {{ tab.label }}<span class="text-xs tabular-nums text-muted-foreground">{{ tab.count }}</span>
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                    <div class="relative w-full sm:w-64">
                        <IconInput v-model="organizationSearch" :icon="Search" type="text" inputmode="search" placeholder="Rechercher une mutuelle" aria-label="Rechercher une mutuelle" class="pe-9" />
                        <button v-if="organizationSearch" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="organizationSearch = ''">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div v-if="selectedOrganizations.length" class="flex flex-col gap-2 border-t border-border bg-muted/40 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-foreground"><span class="font-semibold tabular-nums">{{ selectedOrganizations.length }}</span> {{ selectedOrganizations.length > 1 ? 'sélectionnées' : 'sélectionnée' }}<span class="text-muted-foreground"> · {{ SELECTION_LIMIT }} au plus</span></p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="can('mutual_organizations.export')" as="a" :href="selectionExportUrl" size="sm" variant="outline"><Download class="h-4 w-4" />Exporter</Button>
                        <Button v-if="can('mutual_organizations.archive') && selectedActiveOrganizations.length" type="button" size="sm" variant="outline" @click="openBulkAction('ORGANIZATIONS', 'ARCHIVE')"><Archive class="h-4 w-4" />Archiver</Button>
                        <Button v-if="can('mutual_organizations.restore') && selectedArchivedOrganizations.length" type="button" size="sm" variant="outline" @click="openBulkAction('ORGANIZATIONS', 'RESTORE')"><RotateCcw class="h-4 w-4" />Restaurer</Button>
                        <Button type="button" size="sm" variant="ghost" @click="clearOrganizationSelection">Désélectionner</Button>
                    </div>
                </div>

                <div class="overflow-x-auto border-t border-border">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="border-b border-border">
                            <tr>
                                <th :class="cn(TH, 'w-10 ps-5')">
                                    <Checkbox :model-value="organizationsHeaderState" :disabled="! organizations.length" aria-label="Sélectionner les mutuelles affichées" @update:model-value="toggleVisibleOrganizationSelection" />
                                </th>
                                <th :class="TH">Mutuelle</th>
                                <th :class="cn(TH, 'text-end')">Part mutuelle</th>
                                <th :class="cn(TH, 'text-end')">Part patient</th>
                                <th :class="cn(TH, 'text-end')">Couvertures en cours</th>
                                <th :class="cn(TH, 'text-end')">Dossiers</th>
                                <th :class="cn(TH, 'w-14 pe-5')"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="organization in organizations"
                                :key="organization.uuid"
                                :class="cn('transition-colors hover:bg-muted/40', selectedOrganizationUuids.has(organization.uuid) && 'bg-muted/50')"
                            >
                                <td :class="cn(TD, 'ps-5')">
                                    <Checkbox :model-value="selectedOrganizationUuids.has(organization.uuid)" :aria-label="`Sélectionner ${organization.name}`" @update:model-value="toggleOrganizationSelection(organization.uuid)" />
                                </td>
                                <td :class="cn(TD, 'font-medium', organization.active ? 'text-foreground' : 'text-muted-foreground')">{{ organization.name }}</td>
                                <td :class="cn(TD, 'text-end tabular-nums text-foreground')">{{ formatRate(organization.coverage_rate) }}</td>
                                <td :class="cn(TD, 'text-end tabular-nums text-foreground')">{{ formatRate(organization.patient_rate) }}</td>
                                <td :class="cn(TD, 'text-end tabular-nums', organization.active_coverages_count ? 'text-foreground' : 'text-muted-foreground')">{{ organization.active_coverages_count }}</td>
                                <td :class="cn(TD, 'text-end tabular-nums text-muted-foreground')">{{ organization.coverages_count }}</td>
                                <td :class="cn(TD, 'pe-5 text-end')">
                                    <Button v-if="! organization.active && can('mutual_organizations.restore')" type="button" variant="outline" size="xs" @click="restoreOrganization(organization)"><RotateCcw class="h-3.5 w-3.5" />Restaurer</Button>
                                    <DropdownMenu v-else-if="organization.active && organizationActions().length" :items="organizationActions()" content-class="min-w-[12rem]" @select="(key) => onOrganizationAction(organization, key)">
                                        <template #trigger>
                                            <Button type="button" variant="ghost" icon size="sm" :aria-label="`Actions pour ${organization.name}`"><MoreHorizontal class="h-4 w-4" /></Button>
                                        </template>
                                    </DropdownMenu>
                                </td>
                            </tr>
                            <tr v-if="! organizations.length">
                                <td colspan="7" class="px-5 py-14 text-center">
                                    <p class="text-sm font-medium text-foreground">Aucune mutuelle ne correspond</p>
                                    <p class="mt-1 text-sm text-muted-foreground">Changez d’onglet ou de recherche.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                    {{ plural(organizations.length, 'mutuelle affichée', 'mutuelles affichées') }} · archivage réversible et audité
                </p>
            </Card>
        </div>

        <!-- Importer un fichier Excel -->
        <Dialog
            :open="importOpen"
            size="lg"
            :title="workspaceView === 'ORGANIZATIONS' ? 'Importer des mutuelles' : 'Importer des tarifs'"
            :description="`Site destinataire : ${selectedSite?.site.name ?? '—'}. Le fichier est validé en entier avant d’être appliqué : une ligne refusée n’en applique aucune.`"
            :dismissible="false"
            @update:open="(value) => value || closeImport()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-foreground"><FileSpreadsheet class="h-5 w-5" /></span>
            </template>
            <form id="tariffs-import-form" class="space-y-4" @submit.prevent="submitImport">
                <FormField label="Fichier Excel" required as="div">
                    <template #action>
                        <a :href="importTemplateUrl" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"><Download class="h-3.5 w-3.5" />Modèle Excel</a>
                    </template>
                    <input
                        type="file"
                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        aria-label="Fichier Excel"
                        class="block w-full cursor-pointer rounded-lg border border-input bg-card text-sm text-muted-foreground shadow-sm file:me-3 file:cursor-pointer file:border-0 file:border-e file:border-input file:bg-muted file:px-3 file:py-2 file:text-sm file:font-semibold file:text-foreground focus:outline-none focus:ring-2 focus:ring-ring/25"
                        @change="importForm.file = $event.target.files[0] ?? null"
                    >
                </FormField>
                <p class="rounded-lg border border-border bg-muted/40 px-3.5 py-2.5 text-xs leading-5 text-muted-foreground">
                    {{ workspaceView === 'ORGANIZATIONS' ? 'Colonnes : mutuelle et taux de couverture (100 % par défaut).' : 'Colonnes : code de la désignation et tarifs Sans mutuelle / Mutuelle.' }}
                    Format .xlsx, 1 000 lignes au plus.
                </p>
                <FormError v-for="error in Object.values(importForm.errors)" :key="error">{{ error }}</FormError>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="importForm.processing" @click="closeImport">Annuler</Button>
                <Button type="submit" form="tariffs-import-form" :disabled="importForm.processing || ! importForm.file || ! selectedSite?.ok">
                    <Upload class="h-4 w-4" />{{ importForm.processing ? 'Importation…' : 'Importer' }}
                </Button>
            </template>
        </Dialog>

        <!-- Archiver / restaurer une sélection -->
        <Dialog
            :open="bulkAction !== null"
            :title="bulkAction ? `${bulkAction.mode === 'ARCHIVE' ? 'Archiver' : 'Restaurer'} ${plural(bulkAction.count, bulkAction.entity === 'ITEMS' ? 'désignation' : 'mutuelle', bulkAction.entity === 'ITEMS' ? 'désignations' : 'mutuelles')} ?` : ''"
            :description="`La commande concerne uniquement ${selectedSite?.site.name ?? '—'}. Elle est atomique et auditée.`"
            :dismissible="false"
            @update:open="(value) => value || closeBulkAction()"
        >
            <template #icon>
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', bulkAction?.mode === 'ARCHIVE' ? 'bg-destructive/10 text-destructive' : 'border border-border text-foreground')">
                    <component :is="bulkAction?.mode === 'ARCHIVE' ? Archive : RotateCcw" class="h-5 w-5" />
                </span>
            </template>
            <form id="tariffs-bulk-form" class="space-y-3" @submit.prevent="submitBulkAction">
                <FormField v-if="bulkAction?.mode === 'ARCHIVE'" label="Motif commun" required>
                    <Textarea v-model="bulkForm.reason" :rows="3" placeholder="Précisez la décision d’archivage" />
                </FormField>
                <p v-else class="text-sm text-muted-foreground">Les éléments restaurés redeviennent proposés, avec leurs tarifs d’avant l’archivage.</p>
                <FormError v-for="error in Object.values(bulkForm.errors)" :key="error">{{ error }}</FormError>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="bulkForm.processing" @click="closeBulkAction">Annuler</Button>
                <Button type="submit" form="tariffs-bulk-form" :variant="bulkAction?.mode === 'ARCHIVE' ? 'danger' : 'default'" :disabled="bulkForm.processing || (bulkAction?.mode === 'ARCHIVE' && ! bulkForm.reason.trim())">
                    <component :is="bulkAction?.mode === 'ARCHIVE' ? Archive : RotateCcw" class="h-4 w-4" />Confirmer
                </Button>
            </template>
        </Dialog>

        <!-- Créer / modifier une mutuelle -->
        <Dialog
            :open="organizationModalOpen"
            :title="editingOrganization ? `Modifier « ${editingOrganization.name} »` : 'Nouvelle mutuelle'"
            :description="`Référentiel du site ${selectedSite?.site.name ?? '—'}.`"
            :dismissible="false"
            @update:open="(value) => value || closeOrganizationModal()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-border text-foreground"><component :is="editingOrganization ? Pencil : Building2" class="h-5 w-5" /></span>
            </template>
            <form id="tariffs-organization-form" class="space-y-4" @submit.prevent="submitOrganization">
                <FormField label="Nom de la mutuelle" required :error="organizationForm.errors.name">
                    <IconInput v-model="organizationForm.name" :icon="Building2" placeholder="Ex. ADEFI" maxlength="255" />
                </FormField>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Couverture mutuelle" required :error="organizationForm.errors.coverage_rate">
                        <div class="relative">
                            <IconInput v-model="organizationForm.coverage_rate" :icon="Percent" type="number" min="0" max="100" step="0.01" class="pe-9" />
                            <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">%</span>
                        </div>
                    </FormField>
                    <div class="flex flex-col justify-end">
                        <div class="rounded-lg border border-border bg-muted/40 px-3.5 py-2">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Reste patient</p>
                            <p class="mt-0.5 text-sm font-bold tabular-nums text-foreground">{{ patientRatePreview }}</p>
                        </div>
                    </div>
                </div>
                <p class="rounded-lg bg-muted/40 px-3.5 py-2.5 text-xs leading-5 text-muted-foreground">Exemple : à 80 %, la mutuelle prend en charge 80 % du tarif mutuelle et le patient règle les 20 % restants à la Caisse.</p>
                <FormError v-if="organizationForm.errors.organization">{{ organizationForm.errors.organization }}</FormError>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="organizationForm.processing" @click="closeOrganizationModal">Annuler</Button>
                <Button type="submit" form="tariffs-organization-form" :disabled="organizationForm.processing || ! organizationForm.name.trim()">
                    <CircleCheck class="h-4 w-4" />{{ organizationForm.processing ? 'Enregistrement…' : 'Enregistrer' }}
                </Button>
            </template>
        </Dialog>

        <!-- Archiver une mutuelle -->
        <Dialog
            :open="archivingOrganization !== null"
            :title="archivingOrganization ? `Archiver « ${archivingOrganization.name} » ?` : ''"
            description="Elle ne sera plus proposée pour les nouvelles couvertures. Les dossiers existants restent intacts."
            :dismissible="false"
            @update:open="(value) => value || closeArchiveOrganization()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-destructive/10 text-destructive"><Archive class="h-5 w-5" /></span>
            </template>
            <form id="tariffs-organization-archive-form" @submit.prevent="submitArchiveOrganization">
                <FormField label="Motif" required :error="organizationArchiveForm.errors.reason">
                    <Textarea v-model="organizationArchiveForm.reason" :rows="3" placeholder="Précisez la décision d’archivage" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="organizationArchiveForm.processing" @click="closeArchiveOrganization">Annuler</Button>
                <Button type="submit" form="tariffs-organization-archive-form" variant="danger" :disabled="organizationArchiveForm.processing || ! organizationArchiveForm.reason.trim()">
                    <Archive class="h-4 w-4" />Archiver
                </Button>
            </template>
        </Dialog>

        <!-- Archiver une désignation -->
        <Dialog
            :open="confirmation !== null"
            title="Archiver cette désignation ?"
            description="Elle ne sera plus proposée aux nouveaux passages. Les factures et passages existants ne changent pas."
            :dismissible="false"
            @update:open="(value) => value || closeConfirmation()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-destructive/10 text-destructive"><Archive class="h-5 w-5" /></span>
            </template>
            <form id="tariffs-confirmation-form" @submit.prevent="submitConfirmation">
                <p v-if="confirmation?.item" class="mb-3 text-sm text-foreground"><strong>{{ confirmation.item.name }}</strong> <span class="font-mono text-xs text-muted-foreground">{{ confirmation.item.code }}</span></p>
                <FormField label="Motif" required :error="confirmationForm.errors.reason">
                    <Textarea v-model="confirmationForm.reason" :rows="3" placeholder="La décision, en une phrase" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="confirmationForm.processing" @click="closeConfirmation">Annuler</Button>
                <Button type="submit" form="tariffs-confirmation-form" variant="danger" :disabled="confirmationForm.processing || ! confirmationForm.reason.trim()">
                    <Archive class="h-4 w-4" />Archiver
                </Button>
            </template>
        </Dialog>
    </div>
</template>
