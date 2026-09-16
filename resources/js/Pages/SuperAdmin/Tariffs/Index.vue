<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Archive, Building2, Check, Download, History, ListChecks, Pencil, Plus, RotateCcw, Search, Server, ShieldCheck, Upload, X } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/utilities/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    selectedSiteCode: { type: String, default: null },
});

const { can } = usePermissions();
const firstOnlineSite = props.sites.find((site) => site.ok)?.site.code;
const requestedSite = props.sites.find((site) => site.site.code === props.selectedSiteCode)?.site.code;
const selectedSiteCode = ref(requestedSite ?? firstOnlineSite ?? props.sites[0]?.site.code);
const search = ref('');
const typeFilter = ref('');
const moduleFilter = ref('');
const statusFilter = ref('ALL');
const tariffFilter = ref('ALL');
const workspaceView = ref('TARIFFS');
const organizationSearch = ref('');
const organizationStatus = ref('ALL');
const itemModalOpen = ref(false);
const editingItem = ref(null);
const tariffTarget = ref(null);
const confirmation = ref(null);
const organizationModalOpen = ref(false);
const editingOrganization = ref(null);
const archivingOrganization = ref(null);
const selectedItemUuids = ref(new Set());
const selectedOrganizationUuids = ref(new Set());
const bulkAction = ref(null);
const showImport = ref(false);

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const siteData = computed(() => selectedSite.value?.data ?? {});
const summary = computed(() => siteData.value.summary ?? {
    active: 0,
    archived: 0,
    billable: 0,
    without_standard_tariff: 0,
    without_mutual_tariff: 0,
});
const options = computed(() => siteData.value.options ?? {
    types: [], modules: [], routing_modes: [], tariff_categories: [],
});
const items = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return (siteData.value.items ?? []).filter((item) => {
        const matchesSearch = !needle || [item.name, item.code, item.description]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase().includes(needle));
        const matchesType = !typeFilter.value || item.type === typeFilter.value;
        const matchesModule = !moduleFilter.value || item.module === moduleFilter.value;
        const matchesStatus = statusFilter.value === 'ALL'
            || (statusFilter.value === 'ACTIVE' && !item.archived)
            || (statusFilter.value === 'ARCHIVED' && item.archived);
        const matchesTariff = tariffFilter.value === 'ALL' || item.billable;

        return matchesSearch && matchesType && matchesModule && matchesStatus && matchesTariff;
    });
});
// Domaines réellement présents sur ce site, avec leur avancement de
// tarification (STANDARD) — répond directement à « quels domaines existent
// et lesquels restent à configurer », plutôt que de le cacher dans un menu.
const moduleBreakdown = computed(() => {
    const breakdown = new Map();

    (siteData.value.items ?? []).forEach((item) => {
        if (item.archived) return;

        const entry = breakdown.get(item.module) ?? {
            value: item.module, label: item.module_label, count: 0, priced: 0,
        };
        entry.count += 1;
        if (item.current_standard_tariff) entry.priced += 1;
        breakdown.set(item.module, entry);
    });

    return Array.from(breakdown.values()).sort((left, right) => left.label.localeCompare(right.label, 'fr'));
});
const moduleBreakdownTotals = computed(() => moduleBreakdown.value.reduce((totals, module) => ({
    count: totals.count + module.count,
    priced: totals.priced + module.priced,
}), { count: 0, priced: 0 }));
const selectedItems = computed(() => items.value.filter((item) => selectedItemUuids.value.has(item.uuid)));
const selectedActiveItems = computed(() => selectedItems.value.filter((item) => !item.archived));
const selectedArchivedItems = computed(() => selectedItems.value.filter((item) => item.archived));
const allVisibleItemsSelected = computed(() => items.value.length > 0 && items.value.every((item) => selectedItemUuids.value.has(item.uuid)));
const tariffFilterLabel = computed(() => ({
    STANDARD: 'Sans mutuelle',
    MUTUAL: 'Mutuelle',
}[tariffFilter.value] ?? 'Toutes les grilles'));
const canManageTariffs = computed(() => can('catalog.tariffs.create') || can('catalog.tariffs.update'));
const organizationsSummary = computed(() => siteData.value.mutual_organizations_summary ?? {
    active: 0,
    archived: 0,
    active_coverages: 0,
});
const organizations = computed(() => {
    const needle = organizationSearch.value.trim().toLocaleLowerCase();

    return (siteData.value.mutual_organizations ?? []).filter((organization) => {
        const matchesSearch = !needle || organization.name.toLocaleLowerCase().includes(needle);
        const matchesStatus = organizationStatus.value === 'ALL'
            || (organizationStatus.value === 'ACTIVE' && organization.active)
            || (organizationStatus.value === 'ARCHIVED' && !organization.active);

        return matchesSearch && matchesStatus;
    });
});
const selectedOrganizations = computed(() => organizations.value.filter((organization) => selectedOrganizationUuids.value.has(organization.uuid)));
const selectedActiveOrganizations = computed(() => selectedOrganizations.value.filter((organization) => organization.active));
const selectedArchivedOrganizations = computed(() => selectedOrganizations.value.filter((organization) => !organization.active));
const allVisibleOrganizationsSelected = computed(() => organizations.value.length > 0 && organizations.value.every((organization) => selectedOrganizationUuids.value.has(organization.uuid)));

const itemForm = useForm({
    site_code: selectedSiteCode.value,
    code: '',
    name: '',
    type: 'SERVICE',
    module: 'RECEPTION',
    unit: 'acte',
    billable: true,
    stockable: false,
    reception_selectable: false,
    reception_routing_mode: null,
    staff_coverage_policy: 'UNCLASSIFIED',
    care_requires_allergy_check: false,
    care_recommends_vitals: false,
    description: '',
    tariff_amount: '',
    mutual_tariff_amount: '',
    tariff_reason: '',
});
const tariffForm = useForm({ tariff_category: 'STANDARD', tariff_amount: '', reason: '' });
const confirmationForm = useForm({ reason: '', tariff_category: 'STANDARD' });
const organizationForm = useForm({ site_code: selectedSiteCode.value, name: '', coverage_rate: '100.00' });
const organizationArchiveForm = useForm({ reason: '' });
const bulkForm = useForm({ site_code: selectedSiteCode.value, uuids: [], reason: '' });
const importForm = useForm({ site_code: selectedSiteCode.value, file: null });

const exportUrl = computed(() => {
    const organizationMode = workspaceView.value === 'ORGANIZATIONS';
    const base = organizationMode
        ? '/super-admin/workspaces/tariffs/mutual-organizations/export'
        : '/super-admin/workspaces/tariffs/export';
    const params = new URLSearchParams({ site_code: selectedSiteCode.value });
    const uuids = organizationMode ? selectedOrganizationUuids.value : selectedItemUuids.value;
    uuids.forEach((uuid) => params.append('uuids[]', uuid));

    return `${base}?${params.toString()}`;
});
const importTemplateUrl = computed(() => workspaceView.value === 'ORGANIZATIONS'
    ? '/super-admin/workspaces/tariffs/mutual-organizations/import-template'
    : '/super-admin/workspaces/tariffs/import-template');

const toggleItemSelection = (uuid) => {
    const next = new Set(selectedItemUuids.value);
    next.has(uuid) ? next.delete(uuid) : next.size < 100 && next.add(uuid);
    selectedItemUuids.value = next;
};

const toggleVisibleItemSelection = () => {
    const next = new Set(selectedItemUuids.value);
    if (allVisibleItemsSelected.value) items.value.forEach((item) => next.delete(item.uuid));
    else items.value.forEach((item) => { if (next.size < 100) next.add(item.uuid); });
    selectedItemUuids.value = next;
};

const toggleOrganizationSelection = (uuid) => {
    const next = new Set(selectedOrganizationUuids.value);
    next.has(uuid) ? next.delete(uuid) : next.size < 100 && next.add(uuid);
    selectedOrganizationUuids.value = next;
};

const toggleVisibleOrganizationSelection = () => {
    const next = new Set(selectedOrganizationUuids.value);
    if (allVisibleOrganizationsSelected.value) organizations.value.forEach((organization) => next.delete(organization.uuid));
    else organizations.value.forEach((organization) => { if (next.size < 100) next.add(organization.uuid); });
    selectedOrganizationUuids.value = next;
};

const clearItemSelection = () => { selectedItemUuids.value = new Set(); };
const clearOrganizationSelection = () => { selectedOrganizationUuids.value = new Set(); };

const selectedType = computed(() => options.value.types.find((type) => type.value === itemForm.type));
const isCareService = computed(() => itemForm.type === 'SERVICE' && itemForm.module === 'CARE');
const currentTariff = computed(() => {
    if (!tariffTarget.value) return null;

    return tariffForm.tariff_category === 'MUTUAL'
        ? tariffTarget.value.current_mutual_tariff
        : tariffTarget.value.current_standard_tariff;
});
const tariffHistory = computed(() => (tariffTarget.value?.tariffs ?? [])
    .filter((tariff) => tariff.tariff_category === tariffForm.tariff_category));

watch(() => itemForm.type, (type) => {
    if (editingItem.value) return;

    const metadata = options.value.types.find((entry) => entry.value === type);
    itemForm.billable = Boolean(metadata?.billable);
    itemForm.stockable = Boolean(metadata?.stockable);
    itemForm.reception_selectable = false;
    itemForm.reception_routing_mode = null;
    itemForm.staff_coverage_policy = 'UNCLASSIFIED';

    if (type === 'SERVICE') itemForm.unit = 'acte';
    if (type === 'MEDICINE' || type === 'CONSUMABLE') itemForm.unit = 'unité';
});

watch(isCareService, (enabled) => {
    if (enabled) return;
    itemForm.care_requires_allergy_check = false;
    itemForm.care_recommends_vitals = false;
});

watch(workspaceView, () => {
    showImport.value = false;
    importForm.clearErrors();
    importForm.file = null;
});

const selectSite = (code) => {
    selectedSiteCode.value = code;
    itemForm.site_code = code;
    search.value = '';
    typeFilter.value = '';
    moduleFilter.value = '';
    statusFilter.value = 'ALL';
    tariffFilter.value = 'ALL';
    organizationSearch.value = '';
    organizationStatus.value = 'ALL';
    organizationForm.site_code = code;
    importForm.site_code = code;
    importForm.file = null;
    importForm.clearErrors();
    showImport.value = false;
    clearItemSelection();
    clearOrganizationSelection();

    const url = new URL(window.location.href);
    url.searchParams.set('site', code);
    window.history.replaceState(window.history.state, '', url);
};

const resetItemForm = () => {
    itemForm.reset();
    itemForm.clearErrors();
    itemForm.site_code = selectedSiteCode.value;
    itemForm.type = 'SERVICE';
    itemForm.module = 'RECEPTION';
    itemForm.unit = 'acte';
    itemForm.billable = true;
    itemForm.stockable = false;
    itemForm.reception_selectable = false;
    itemForm.reception_routing_mode = null;
};

const openCreate = () => {
    editingItem.value = null;
    resetItemForm();
    itemModalOpen.value = true;
};

const openEdit = (item) => {
    editingItem.value = item;
    itemForm.clearErrors();
    Object.assign(itemForm, {
        site_code: selectedSiteCode.value,
        code: item.code,
        name: item.name,
        type: item.type,
        module: item.module,
        unit: item.unit,
        billable: item.billable,
        stockable: item.stockable,
        reception_selectable: item.reception_selectable,
        reception_routing_mode: item.reception_routing_mode,
        staff_coverage_policy: item.staff_coverage_policy,
        care_requires_allergy_check: item.care_requires_allergy_check,
        care_recommends_vitals: item.care_recommends_vitals,
        description: item.description ?? '',
        tariff_amount: '',
        mutual_tariff_amount: '',
        tariff_reason: '',
    });
    itemModalOpen.value = true;
};

const closeItemModal = () => {
    if (itemForm.processing) return;
    itemModalOpen.value = false;
    editingItem.value = null;
    resetItemForm();
};

const submitItem = () => {
    if (editingItem.value) {
        itemForm.transform((data) => ({
            name: data.name,
            module: data.module,
            unit: data.unit,
            reception_selectable: data.reception_selectable,
            reception_routing_mode: data.reception_selectable ? data.reception_routing_mode : null,
            staff_coverage_policy: data.staff_coverage_policy,
            care_requires_allergy_check: isCareService.value ? data.care_requires_allergy_check : false,
            care_recommends_vitals: isCareService.value ? data.care_recommends_vitals : false,
            description: data.description || null,
        })).put(`/super-admin/workspaces/tariffs/items/${selectedSiteCode.value}/${editingItem.value.uuid}`, {
            preserveScroll: true,
            onSuccess: closeItemModal,
        });
        return;
    }

    itemForm.transform((data) => ({
        ...data,
        care_requires_allergy_check: isCareService.value ? data.care_requires_allergy_check : false,
        care_recommends_vitals: isCareService.value ? data.care_recommends_vitals : false,
    })).post('/super-admin/workspaces/tariffs/items', {
        preserveScroll: true,
        onSuccess: closeItemModal,
    });
};

const chooseTariffCategory = (category) => {
    tariffForm.tariff_category = category;
    const current = category === 'MUTUAL'
        ? tariffTarget.value?.current_mutual_tariff
        : tariffTarget.value?.current_standard_tariff;
    tariffForm.tariff_amount = current?.amount ?? '';
    tariffForm.reason = '';
    tariffForm.clearErrors();
};

const openTariff = (item, category = 'STANDARD') => {
    tariffTarget.value = item;
    tariffForm.reset();
    chooseTariffCategory(category);
};

const closeTariff = () => {
    if (tariffForm.processing) return;
    tariffTarget.value = null;
    tariffForm.reset();
    tariffForm.clearErrors();
};

const submitTariff = () => tariffForm.post(
    `/super-admin/workspaces/tariffs/items/${selectedSiteCode.value}/${tariffTarget.value.uuid}/tariffs`,
    { preserveScroll: true, onSuccess: closeTariff },
);

const requestArchiveItem = (item) => {
    confirmationForm.reset();
    confirmationForm.clearErrors();
    confirmation.value = { mode: 'item', item };
};

const requestArchiveTariff = () => {
    confirmationForm.reset();
    confirmationForm.clearErrors();
    confirmationForm.tariff_category = tariffForm.tariff_category;
    confirmation.value = { mode: 'tariff', item: tariffTarget.value };
    tariffTarget.value = null;
};

const closeConfirmation = () => {
    if (confirmationForm.processing) return;
    confirmation.value = null;
    confirmationForm.reset();
};

const submitConfirmation = () => {
    const item = confirmation.value.item;
    const base = `/super-admin/workspaces/tariffs/items/${selectedSiteCode.value}/${item.uuid}`;
    const options = { preserveScroll: true, onSuccess: closeConfirmation };

    if (confirmation.value.mode === 'tariff') {
        confirmationForm.post(`${base}/tariffs/archive`, options);
        return;
    }

    confirmationForm.delete(base, options);
};

const restoreItem = (item) => router.post(
    `/super-admin/workspaces/tariffs/items/${selectedSiteCode.value}/${item.uuid}/restore`,
    {},
    { preserveScroll: true },
);

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

const toggleImport = () => {
    showImport.value = !showImport.value;
    importForm.clearErrors();
    importForm.site_code = selectedSiteCode.value;
    importForm.file = null;
};

const submitImport = () => {
    const endpoint = workspaceView.value === 'ORGANIZATIONS'
        ? '/super-admin/workspaces/tariffs/mutual-organizations/import'
        : '/super-admin/workspaces/tariffs/import';

    importForm.post(endpoint, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showImport.value = false;
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

const openBulkAction = (entity, mode) => {
    const targets = entity === 'ITEMS'
        ? (mode === 'ARCHIVE' ? selectedActiveItems.value : selectedArchivedItems.value)
        : (mode === 'ARCHIVE' ? selectedActiveOrganizations.value : selectedArchivedOrganizations.value);
    if (!targets.length) return;

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

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Tarifs & organismes" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground"><ListChecks class="h-5 w-5" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Tarifs & organismes</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Désignations, grilles tarifaires et partenaires de couverture propres à chaque clinique.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="hidden items-center gap-2 rounded border border-border bg-card px-3 py-2 text-xs text-muted-foreground md:inline-flex"><ShieldCheck class="h-4 w-4" />Écritures auditées sur le site destinataire</span>
                <Button v-if="(workspaceView === 'TARIFFS' && can('catalog.tariffs.export')) || (workspaceView === 'ORGANIZATIONS' && can('mutual_organizations.export'))" as="a" :href="exportUrl" size="rg" variant="white-outline" :disabled="!selectedSite?.ok"><Download class="h-4.5 w-4.5" />Exporter Excel</Button>
                <Button v-if="(workspaceView === 'TARIFFS' && can('catalog.tariffs.import')) || (workspaceView === 'ORGANIZATIONS' && can('mutual_organizations.import'))" size="rg" variant="white-outline" type="button" @click="toggleImport"><component class="h-4.5 w-4.5" :is="showImport ? X : Upload" />{{ showImport ? 'Fermer' : 'Importer Excel' }}</Button>
                <Button v-if="workspaceView === 'TARIFFS' && can('catalog.items.create')" size="rg" :disabled="!selectedSite?.ok" @click="openCreate"><Plus class="h-4.5 w-4.5" />Nouvelle désignation</Button>
                <Button v-else-if="workspaceView === 'ORGANIZATIONS' && can('mutual_organizations.create')" size="rg" :disabled="!selectedSite?.ok" @click="openCreateOrganization"><Plus class="h-4.5 w-4.5" />Nouvel organisme</Button>
            </div>
        </header>

        <form v-if="showImport" class="rounded-lg border border-border bg-card p-5" @submit.prevent="submitImport">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                <div class="min-w-52"><label class="mb-1.5 block text-sm font-medium text-foreground">Site destinataire</label><input :value="selectedSite?.site.name" disabled class="h-10 w-full rounded border border-border bg-muted px-3 text-sm text-muted-foreground"></div>
                <div class="min-w-0 flex-1"><div class="mb-1.5 flex items-center justify-between gap-3"><label class="block text-sm font-medium text-foreground">Fichier Excel <span class="text-red-500">*</span></label><a :href="importTemplateUrl" class="text-xs font-bold text-primary hover:text-primary">Télécharger le modèle Excel</a></div><input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block h-10 w-full rounded border border-border bg-card px-3 py-2 text-sm text-muted-foreground file:me-3 file:border-0 file:bg-transparent file:text-xs file:font-bold file:text-muted-foreground" @change="importForm.file = $event.target.files[0] ?? null"><p class="mt-1 text-xs text-muted-foreground">{{ workspaceView === 'ORGANIZATIONS' ? 'Organisme + taux de couverture (100 % par défaut)' : 'Code désignation + tarifs Sans mutuelle / Mutuelle' }} · .xlsx · 1 000 lignes maximum.</p><p v-for="error in Object.values(importForm.errors)" :key="error" class="mt-1 text-xs text-red-600">{{ error }}</p></div>
                <Button size="rg" :disabled="importForm.processing || !selectedSite?.ok"><Upload class="h-4.5 w-4.5" />{{ importForm.processing ? 'Importation…' : 'Importer' }}</Button>
            </div>
        </form>

        <section class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="flex gap-1 overflow-x-auto border-b border-border bg-muted/70 p-2 /40">
                <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex min-w-40 items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-bold transition', selectedSiteCode === site.site.code ? 'bg-card text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="selectSite(site.site.code)">
                    <span :class="['h-2 w-2 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'UNCONFIGURED' ? 'bg-muted-foreground/40' : 'bg-red-500']" />
                    {{ site.site.name }}
                </button>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <span class="flex h-11 w-11 items-center justify-center rounded bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span>
                <h2 class="mt-3 text-sm font-bold text-foreground">API indisponible pour {{ selectedSite?.site.name }}</h2>
                <p class="mt-1 max-w-xl text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p>
                <p class="mt-3 text-xs text-muted-foreground">Les autres sites restent utilisables et aucune base clinique n’est accédée directement.</p>
            </div>

            <template v-else>
                <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <nav class="inline-flex w-fit rounded border border-border bg-muted p-1" aria-label="Sections du référentiel">
                        <button type="button" :class="['inline-flex items-center gap-2 rounded px-4 py-2 text-sm font-bold transition', workspaceView === 'TARIFFS' ? 'bg-card text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="workspaceView = 'TARIFFS'"><ListChecks class="h-4 w-4" />Désignations & tarifs</button>
                        <button v-if="can('mutual_organizations.view')" type="button" :class="['inline-flex items-center gap-2 rounded px-4 py-2 text-sm font-bold transition', workspaceView === 'ORGANIZATIONS' ? 'bg-card text-foreground shadow-sm ' : 'text-muted-foreground hover:text-foreground']" @click="workspaceView = 'ORGANIZATIONS'"><Building2 class="h-4 w-4" />Mutuelles & partenaires <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">{{ organizationsSummary.active }}</span></button>
                    </nav>
                    <p class="text-xs text-muted-foreground">Site {{ selectedSite.site.name }} · données API</p>
                </div>

                <template v-if="workspaceView === 'TARIFFS'">
                <div class="grid border-b border-border sm:grid-cols-2 xl:grid-cols-5">
                    <div class="border-b border-border px-5 py-3.5 sm:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Actives</p><p class="mt-1 text-xl font-bold text-foreground">{{ summary.active }}</p></div>
                    <div class="border-b border-border px-5 py-3.5 xl:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Facturables</p><p class="mt-1 text-xl font-bold text-foreground">{{ summary.billable }}</p></div>
                    <div class="border-b border-border px-5 py-3.5 sm:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Sans tarif standard</p><p :class="['mt-1 text-xl font-bold', summary.without_standard_tariff ? 'text-amber-600' : 'text-foreground']">{{ summary.without_standard_tariff }}</p></div>
                    <div class="border-b border-border px-5 py-3.5 xl:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Sans tarif mutuelle</p><p :class="['mt-1 text-xl font-bold', summary.without_mutual_tariff ? 'text-amber-600' : 'text-foreground']">{{ summary.without_mutual_tariff }}</p></div>
                    <div class="px-5 py-3.5"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Archivées</p><p class="mt-1 text-xl font-bold text-foreground">{{ summary.archived }}</p></div>
                </div>

                <div class="flex flex-col gap-3 border-b border-border p-4 xl:flex-row xl:items-center xl:justify-between">
                    <label class="relative block w-full xl:max-w-md"><Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-muted-foreground h-4.5 w-4.5" /><input v-model="search" type="search" class="h-9 w-full rounded border border-border bg-card ps-10 pe-3 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Rechercher un code ou une désignation"></label>
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <select v-model="moduleFilter" aria-label="Filtrer par domaine" class="h-9 min-w-44 rounded border border-border bg-card px-3 text-sm font-semibold text-muted-foreground outline-none focus:border-primary">
                            <option value="">Tous les domaines · {{ moduleBreakdownTotals.priced }}/{{ moduleBreakdownTotals.count }}</option>
                            <option v-for="module in moduleBreakdown" :key="module.value" :value="module.value">{{ module.label }} · {{ module.priced }}/{{ module.count }}</option>
                        </select>
                        <select v-model="typeFilter" class="h-9 min-w-40 rounded border border-border bg-card px-3 text-sm text-muted-foreground outline-none focus:border-primary"><option value="">Tous les types</option><option v-for="type in options.types" :key="type.value" :value="type.value">{{ type.label }}</option></select>
                        <select v-model="tariffFilter" aria-label="Filtrer par grille tarifaire" class="h-9 min-w-40 rounded border border-border bg-card px-3 text-sm text-muted-foreground outline-none focus:border-primary"><option value="ALL">Toutes les grilles</option><option value="STANDARD">Sans mutuelle</option><option value="MUTUAL">Mutuelle</option></select>
                        <select v-model="statusFilter" class="h-9 min-w-32 rounded border border-border bg-card px-3 text-sm text-muted-foreground outline-none focus:border-primary"><option value="ALL">Tous les états</option><option value="ACTIVE">Actives</option><option value="ARCHIVED">Archivées</option></select>
                    </div>
                </div>

                <div v-if="selectedItems.length" class="flex flex-col gap-3 border-b border-border bg-primary/5 px-5 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><span class="flex h-8 min-w-8 items-center justify-center rounded bg-primary px-2 text-xs font-bold text-white">{{ selectedItems.length }}</span><div><p class="text-sm font-bold text-foreground">désignation(s) sélectionnée(s)</p><p class="text-xs text-muted-foreground">Opération atomique sur {{ selectedSite.site.name }} · 100 maximum</p></div></div><div class="flex flex-wrap items-center gap-2"><Button v-if="can('catalog.tariffs.export')" as="a" :href="exportUrl" size="sm" variant="white-outline"><Download class="h-4 w-4" />Exporter</Button><Button v-if="can('catalog.items.delete') && selectedActiveItems.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('ITEMS', 'ARCHIVE')"><Archive class="h-4 w-4" />Archiver ({{ selectedActiveItems.length }})</Button><Button v-if="can('catalog.items.restore') && selectedArchivedItems.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('ITEMS', 'RESTORE')"><RotateCcw class="h-4 w-4" />Restaurer ({{ selectedArchivedItems.length }})</Button><button type="button" class="px-2 py-1 text-xs font-bold text-muted-foreground hover:text-foreground" @click="clearItemSelection">Désélectionner</button></div></div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1180px] text-sm">
<thead class="bg-muted/70 text-[11px] font-medium uppercase tracking-wide text-muted-foreground /40"><tr><th class="w-10 px-4 py-3 text-start"><input type="checkbox" class="rounded border-input text-primary focus:ring-ring" :checked="allVisibleItemsSelected" aria-label="Sélectionner toutes les désignations affichées" @change="toggleVisibleItemSelection"></th><th class="px-3 py-3 text-start">Désignation</th><th class="px-4 py-3 text-start">Domaine</th><th class="px-4 py-3 text-start">Parcours</th><th v-if="tariffFilter !== 'MUTUAL'" class="px-4 py-3 text-end">Sans mutuelle</th><th v-if="tariffFilter !== 'STANDARD'" class="px-4 py-3 text-end">Mutuelle</th><th class="px-4 py-3 text-start">État</th><th class="px-5 py-3 text-end">Actions</th></tr></thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="item in items" :key="item.uuid" :class="['hover:bg-muted/60 dark:hover:bg-muted/30', selectedItemUuids.has(item.uuid) ? 'bg-primary/5 ' : '']">
                                <td class="px-4 py-3"><input type="checkbox" class="rounded border-input text-primary focus:ring-ring" :checked="selectedItemUuids.has(item.uuid)" :aria-label="`Sélectionner ${item.name}`" @change="toggleItemSelection(item.uuid)"></td>
                                <td class="px-3 py-3"><div class="flex items-center gap-2"><p class="font-bold text-foreground">{{ item.name }}</p><span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">{{ item.code }}</span></div><p class="mt-1 line-clamp-1 text-xs text-muted-foreground">{{ item.description || `Unité : ${item.unit}` }}</p></td>
                                <td class="px-4 py-3"><p class="font-medium text-muted-foreground">{{ item.module_label }}</p><p class="mt-0.5 text-xs text-muted-foreground">{{ item.type_label }} · {{ item.unit }}</p></td>
                                <td class="px-4 py-3"><p v-if="item.reception_selectable" class="font-medium text-muted-foreground">{{ item.reception_routing_label }}</p><p v-else class="text-xs text-muted-foreground">Non proposée à la Réception</p></td>
                                <td v-if="tariffFilter !== 'MUTUAL'" class="px-4 py-3 text-end"><button v-if="item.current_standard_tariff && !item.archived && canManageTariffs" type="button" class="font-bold text-foreground hover:text-primary" @click="openTariff(item, 'STANDARD')">{{ formatMoney(item.current_standard_tariff.amount, item.current_standard_tariff.currency) }}</button><span v-else-if="item.current_standard_tariff" class="font-bold text-foreground">{{ formatMoney(item.current_standard_tariff.amount, item.current_standard_tariff.currency) }}</span><button v-else-if="item.billable && !item.archived && canManageTariffs" class="text-xs font-bold text-primary" type="button" @click="openTariff(item, 'STANDARD')">Configurer</button><span v-else class="text-xs text-muted-foreground">{{ item.billable ? 'Non configuré' : 'Non facturable' }}</span></td>
                                <td v-if="tariffFilter !== 'STANDARD'" class="px-4 py-3 text-end"><button v-if="item.current_mutual_tariff && !item.archived && canManageTariffs" type="button" class="font-bold text-foreground hover:text-primary" @click="openTariff(item, 'MUTUAL')">{{ formatMoney(item.current_mutual_tariff.amount, item.current_mutual_tariff.currency) }}</button><span v-else-if="item.current_mutual_tariff" class="font-bold text-foreground">{{ formatMoney(item.current_mutual_tariff.amount, item.current_mutual_tariff.currency) }}</span><button v-else-if="item.billable && !item.archived && canManageTariffs" class="text-xs font-bold text-primary" type="button" @click="openTariff(item, 'MUTUAL')">Configurer</button><span v-else class="text-xs text-muted-foreground">{{ item.billable ? 'Non configuré' : '—' }}</span></td>
                                <td class="px-4 py-3"><span v-if="item.archived" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><span class="h-1.5 w-1.5 rounded-full bg-muted-foreground" />Archivée</span><span v-else class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Active</span></td>
                                <td class="px-5 py-3 text-end"><div class="inline-flex gap-1"><button v-if="!item.archived && can('catalog.items.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Modifier" @click="openEdit(item)"><Pencil class="h-4 w-4" /></button><button v-if="!item.archived && item.billable && canManageTariffs" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Tarifs et historique" @click="openTariff(item, tariffFilter === 'MUTUAL' ? 'MUTUAL' : 'STANDARD')"><History class="h-4 w-4" /></button><button v-if="!item.archived && can('catalog.items.delete')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900" title="Archiver" @click="requestArchiveItem(item)"><Archive class="h-4 w-4" /></button><button v-if="item.archived && can('catalog.items.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Restaurer" @click="restoreItem(item)"><RotateCcw class="h-4 w-4" /></button></div></td>
                            </tr>
                            <tr v-if="!items.length"><td :colspan="tariffFilter === 'ALL' ? 8 : 7" class="px-5 py-12 text-center"><ListChecks class="text-muted-foreground h-6 w-6" /><p class="mt-2 text-sm font-medium text-muted-foreground">Aucune désignation ne correspond à ces filtres.</p></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between border-t border-border px-5 py-3 text-xs text-muted-foreground"><span>{{ items.length }} affichée(s) · {{ tariffFilterLabel }}</span><span>Site {{ selectedSite.site.name }} · données API</span></div>
                </template>

                <template v-else>
                    <div class="grid border-b border-border sm:grid-cols-3">
                        <div class="border-b border-border px-5 py-3.5 sm:border-b-0 sm:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Organismes actifs</p><p class="mt-1 text-xl font-bold text-foreground">{{ organizationsSummary.active }}</p></div>
                        <div class="border-b border-border px-5 py-3.5 sm:border-b-0 sm:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Couvertures en cours</p><p class="mt-1 text-xl font-bold text-foreground">{{ organizationsSummary.active_coverages }}</p></div>
                        <div class="px-5 py-3.5"><p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Archivés</p><p class="mt-1 text-xl font-bold text-foreground">{{ organizationsSummary.archived }}</p></div>
                    </div>

                    <div class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center sm:justify-between">
                        <label class="relative block w-full sm:max-w-md"><Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-muted-foreground h-4.5 w-4.5" /><input v-model="organizationSearch" type="search" class="h-9 w-full rounded border border-border bg-card ps-10 pe-3 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Rechercher une mutuelle ou un partenaire"></label>
                        <select v-model="organizationStatus" class="h-9 min-w-40 rounded border border-border bg-card px-3 text-sm text-muted-foreground outline-none focus:border-primary"><option value="ALL">Tous les états</option><option value="ACTIVE">Actifs</option><option value="ARCHIVED">Archivés</option></select>
                    </div>

                    <div class="border-b border-border bg-muted/60 px-5 py-3 text-xs leading-5 text-muted-foreground /30">
                        <strong class="text-muted-foreground">Règle de classement :</strong> « Sans mutuelle » correspond au tarif standard. « Avantage Personnel » est géré par le dispositif RH du personnel ; ces deux valeurs ne sont donc pas des organismes.
                    </div>

                    <div v-if="selectedOrganizations.length" class="flex flex-col gap-3 border-b border-border bg-primary/5 px-5 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><span class="flex h-8 min-w-8 items-center justify-center rounded bg-primary px-2 text-xs font-bold text-white">{{ selectedOrganizations.length }}</span><div><p class="text-sm font-bold text-foreground">organisme(s) sélectionné(s)</p><p class="text-xs text-muted-foreground">Archivage réversible et audité · 100 maximum</p></div></div><div class="flex flex-wrap items-center gap-2"><Button v-if="can('mutual_organizations.export')" as="a" :href="exportUrl" size="sm" variant="white-outline"><Download class="h-4 w-4" />Exporter</Button><Button v-if="can('mutual_organizations.archive') && selectedActiveOrganizations.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('ORGANIZATIONS', 'ARCHIVE')"><Archive class="h-4 w-4" />Archiver ({{ selectedActiveOrganizations.length }})</Button><Button v-if="can('mutual_organizations.restore') && selectedArchivedOrganizations.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('ORGANIZATIONS', 'RESTORE')"><RotateCcw class="h-4 w-4" />Restaurer ({{ selectedArchivedOrganizations.length }})</Button><button type="button" class="px-2 py-1 text-xs font-bold text-muted-foreground hover:text-foreground" @click="clearOrganizationSelection">Désélectionner</button></div></div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px] text-sm">
                            <thead class="bg-muted/70 text-[11px] font-medium uppercase tracking-wide text-muted-foreground /40"><tr><th class="w-10 px-4 py-3 text-start"><input type="checkbox" class="rounded border-input text-primary focus:ring-ring" :checked="allVisibleOrganizationsSelected" aria-label="Sélectionner tous les organismes affichés" @change="toggleVisibleOrganizationSelection"></th><th class="px-3 py-3 text-start">Mutuelle / partenaire</th><th class="px-4 py-3 text-end">Part mutuelle</th><th class="px-4 py-3 text-end">Part patient</th><th class="px-4 py-3 text-start">Couvertures actives</th><th class="px-4 py-3 text-start">Dossiers associés</th><th class="px-4 py-3 text-start">État</th><th class="px-5 py-3 text-end">Actions</th></tr></thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="organization in organizations" :key="organization.uuid" :class="selectedOrganizationUuids.has(organization.uuid) ? 'bg-primary/5 ' : organization.active ? '' : 'bg-muted/40'">
                                    <td class="px-4 py-3"><input type="checkbox" class="rounded border-input text-primary focus:ring-ring" :checked="selectedOrganizationUuids.has(organization.uuid)" :aria-label="`Sélectionner ${organization.name}`" @change="toggleOrganizationSelection(organization.uuid)"></td>
                                    <td class="px-3 py-3"><p class="font-bold text-foreground">{{ organization.name }}</p><p class="mt-0.5 font-mono text-[10px] text-muted-foreground">{{ organization.uuid }}</p></td>
                                    <td class="px-4 py-3 text-end"><span class="inline-flex rounded bg-emerald-50 px-2 py-1 text-xs font-bold tabular-nums text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">{{ Number(organization.coverage_rate).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) }} %</span></td>
                                    <td class="px-4 py-3 text-end font-bold tabular-nums text-foreground">{{ Number(organization.patient_rate).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) }} %</td>
                                    <td class="px-4 py-3 font-bold text-foreground">{{ organization.active_coverages_count }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ organization.coverages_count }}</td>
                                    <td class="px-4 py-3"><span v-if="organization.active" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" />Actif</span><span v-else class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><span class="h-1.5 w-1.5 rounded-full bg-muted-foreground" />Archivé</span></td>
                                    <td class="px-5 py-3 text-end"><div class="inline-flex gap-1"><button v-if="organization.active && can('mutual_organizations.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Modifier" @click="openEditOrganization(organization)"><Pencil class="h-4 w-4" /></button><button v-if="organization.active && can('mutual_organizations.archive')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900" title="Archiver" @click="openArchiveOrganization(organization)"><Archive class="h-4 w-4" /></button><button v-if="!organization.active && can('mutual_organizations.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Restaurer" @click="restoreOrganization(organization)"><RotateCcw class="h-4 w-4" /></button></div></td>
                                </tr>
                                <tr v-if="!organizations.length"><td colspan="8" class="px-5 py-12 text-center"><Building2 class="text-muted-foreground h-6 w-6" /><p class="mt-2 text-sm font-medium text-muted-foreground">Aucun organisme ne correspond à ces filtres.</p></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center justify-between border-t border-border px-5 py-3 text-xs text-muted-foreground"><span>{{ organizations.length }} organisme(s) affiché(s)</span><span>Archivage réversible et audité</span></div>
                </template>
            </template>
        </section>

        <div v-if="bulkAction" class="fixed inset-0 z-[1350] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" @click.self="closeBulkAction">
            <form class="w-full max-w-md rounded-lg border border-border bg-card p-5 shadow-xl" @submit.prevent="submitBulkAction">
                <div class="flex items-start gap-3"><span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded', bulkAction.mode === 'ARCHIVE' ? 'bg-red-50 text-red-600 dark:bg-red-950/30' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30']"><component class="h-4.5 w-4.5" :is="bulkAction.mode === 'ARCHIVE' ? Archive : RotateCcw" /></span><div><h2 class="text-base font-bold text-foreground">{{ bulkAction.mode === 'ARCHIVE' ? 'Archiver' : 'Restaurer' }} {{ bulkAction.count }} {{ bulkAction.entity === 'ITEMS' ? 'désignation(s)' : 'organisme(s)' }} ?</h2><p class="mt-1 text-sm leading-5 text-muted-foreground">La commande concerne uniquement {{ selectedSite.site.name }}. Elle est atomique et auditée.</p></div></div>
                <template v-if="bulkAction.mode === 'ARCHIVE'"><label class="mb-1.5 mt-5 block text-sm font-medium text-foreground">Motif commun <span class="text-red-500">*</span></label><textarea v-model="bulkForm.reason" rows="3" class="w-full rounded border border-border bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-primary" placeholder="Précisez la décision d’archivage"></textarea></template>
                <p v-for="error in Object.values(bulkForm.errors)" :key="error" class="mt-2 text-xs text-red-600">{{ error }}</p>
                <div class="mt-5 flex justify-end gap-3"><Button size="rg" variant="white-outline" type="button" @click="closeBulkAction">Annuler</Button><Button size="rg" :variant="bulkAction.mode === 'ARCHIVE' ? 'danger' : 'primary'" :disabled="bulkForm.processing"><component :is="bulkAction.mode === 'ARCHIVE' ? Archive : RotateCcw" />Confirmer</Button></div>
            </form>
        </div>

        <div v-if="organizationModalOpen" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" @click.self="closeOrganizationModal">
            <form class="w-full max-w-lg overflow-hidden rounded-lg border border-border bg-card shadow-xl" @submit.prevent="submitOrganization">
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"><div><h2 class="text-lg font-bold text-foreground">{{ editingOrganization ? 'Modifier l’organisme' : 'Nouvel organisme' }}</h2><p class="mt-1 text-xs text-muted-foreground">Référentiel du site <strong>{{ selectedSite.site.name }}</strong>.</p></div><button type="button" class="text-muted-foreground hover:text-foreground" @click="closeOrganizationModal"><X class="h-5 w-5" /></button></header>
                <div class="space-y-4 p-5"><div><label class="mb-1.5 block text-sm font-medium text-foreground">Nom de la mutuelle ou du partenaire <span class="text-red-500">*</span></label><input v-model="organizationForm.name" autofocus class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Ex. ADEFI"><p v-if="organizationForm.errors.name" class="mt-1 text-xs text-red-600">{{ organizationForm.errors.name }}</p></div><div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"><div><label class="mb-1.5 block text-sm font-medium text-foreground">Couverture mutuelle (%) <span class="text-red-500">*</span></label><div class="relative"><input v-model="organizationForm.coverage_rate" type="number" min="0" max="100" step="0.01" class="h-9 w-full rounded border border-border bg-card px-3 pe-9 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25"><span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">%</span></div><p v-if="organizationForm.errors.coverage_rate" class="mt-1 text-xs text-red-600">{{ organizationForm.errors.coverage_rate }}</p></div><div class="rounded border border-border bg-muted px-3 py-2"><p class="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Reste patient</p><p class="mt-0.5 text-sm font-bold text-foreground">{{ Math.max(0, 100 - Number(organizationForm.coverage_rate || 0)).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) }} %</p></div></div><p class="rounded bg-muted px-3 py-2 text-xs leading-5 text-muted-foreground">Exemple : à 80 %, la mutuelle prend en charge 80 % du tarif mutuelle et le patient règle les 20 % restants à la Caisse.</p><p v-if="organizationForm.errors.organization" class="text-xs text-red-600">{{ organizationForm.errors.organization }}</p></div>
                <footer class="flex justify-end gap-3 border-t border-border px-5 py-4"><Button type="button" size="rg" variant="white-outline" @click="closeOrganizationModal">Annuler</Button><Button size="rg" :disabled="organizationForm.processing"><Check class="h-4 w-4" />Enregistrer</Button></footer>
            </form>
        </div>

        <div v-if="archivingOrganization" class="fixed inset-0 z-[1300] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true">
            <form class="w-full max-w-md rounded-lg border border-border bg-card p-5 shadow-xl" @submit.prevent="submitArchiveOrganization"><div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-red-50 text-red-600 dark:bg-red-950/30"><Archive class="h-4.5 w-4.5" /></span><div><h2 class="text-base font-bold text-foreground">Archiver cet organisme ?</h2><p class="mt-1 text-sm leading-5 text-muted-foreground"><strong>{{ archivingOrganization.name }}</strong> ne sera plus proposé dans les nouvelles couvertures. Les dossiers patients existants restent intacts.</p></div></div><label class="mb-1.5 mt-5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></label><textarea v-model="organizationArchiveForm.reason" rows="3" class="w-full rounded border border-border bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-primary" placeholder="Précisez la décision d’archivage"></textarea><p v-if="organizationArchiveForm.errors.reason" class="mt-1 text-xs text-red-600">{{ organizationArchiveForm.errors.reason }}</p><div class="mt-5 flex justify-end gap-3"><Button size="rg" variant="white-outline" type="button" @click="closeArchiveOrganization">Annuler</Button><Button size="rg" variant="danger" :disabled="organizationArchiveForm.processing"><Archive class="h-4 w-4" />Archiver</Button></div></form>
        </div>

        <div v-if="itemModalOpen" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeItemModal">
            <form class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-border bg-card shadow-xl" @submit.prevent="submitItem">
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"><div><h2 class="text-lg font-bold text-foreground">{{ editingItem ? 'Modifier la désignation' : 'Nouvelle désignation' }}</h2><p class="mt-1 text-xs text-muted-foreground">Site destinataire : <strong>{{ selectedSite.site.name }}</strong>. Le code et le type sont immuables après création.</p></div><button type="button" class="text-muted-foreground hover:text-foreground" @click="closeItemModal"><X class="h-5 w-5" /></button></header>
                <div class="overflow-y-auto p-5">
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <div><label class="mb-1.5 block text-sm font-medium text-foreground">Code <span class="text-red-500">*</span></label><input v-model="itemForm.code" :disabled="Boolean(editingItem)" class="h-9 w-full rounded border border-border bg-card px-3 text-sm uppercase text-foreground outline-none focus:border-primary disabled:bg-muted" placeholder="ECHO-ABD"><p v-if="itemForm.errors.code" class="mt-1 text-xs text-red-600">{{ itemForm.errors.code }}</p></div>
                        <div class="md:col-span-1 lg:col-span-2"><label class="mb-1.5 block text-sm font-medium text-foreground">Désignation <span class="text-red-500">*</span></label><input v-model="itemForm.name" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary" placeholder="Nom clair de la prestation"><p v-if="itemForm.errors.name" class="mt-1 text-xs text-red-600">{{ itemForm.errors.name }}</p></div>
                        <div><label class="mb-1.5 block text-sm font-medium text-foreground">Unité <span class="text-red-500">*</span></label><input v-model="itemForm.unit" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary" placeholder="acte"><p v-if="itemForm.errors.unit" class="mt-1 text-xs text-red-600">{{ itemForm.errors.unit }}</p></div>
                        <div><label class="mb-1.5 block text-sm font-medium text-foreground">Type <span class="text-red-500">*</span></label><select v-model="itemForm.type" :disabled="Boolean(editingItem)" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary disabled:bg-muted"><option v-for="type in options.types" :key="type.value" :value="type.value">{{ type.label }}</option></select></div>
                        <div><label class="mb-1.5 block text-sm font-medium text-foreground">Module <span class="text-red-500">*</span></label><select v-model="itemForm.module" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><option v-for="module in options.modules" :key="module.value" :value="module.value">{{ module.label }}</option></select><p v-if="itemForm.errors.module" class="mt-1 text-xs text-red-600">{{ itemForm.errors.module }}</p></div>
                        <div class="md:col-span-2"><label class="mb-1.5 block text-sm font-medium text-foreground">Description</label><input v-model="itemForm.description" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary" placeholder="Précision facultative"></div>
                    </div>

                    <div v-if="itemForm.type === 'SERVICE'" class="mt-5 rounded border border-border p-4"><label class="flex items-start gap-3"><input v-model="itemForm.reception_selectable" type="checkbox" class="mt-0.5 rounded border-input text-primary focus:ring-ring"><span><span class="block text-sm font-bold text-foreground">Disponible à la Réception</span><span class="mt-0.5 block text-xs text-muted-foreground">La réceptionniste pourra choisir cette prestation lors de la création du passage.</span></span></label><div v-if="itemForm.reception_selectable" class="mt-4 max-w-xl"><label class="mb-1.5 block text-sm font-medium text-foreground">Parcours clinique <span class="text-red-500">*</span></label><select v-model="itemForm.reception_routing_mode" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><option :value="null">Choisir le parcours</option><option v-for="mode in options.routing_modes" :key="mode.value" :value="mode.value">{{ mode.label }}</option></select><p v-if="itemForm.errors.reception_routing_mode" class="mt-1 text-xs text-red-600">{{ itemForm.errors.reception_routing_mode }}</p></div></div>

                    <div v-if="isCareService" class="mt-4 grid gap-3 rounded border border-border p-4 md:grid-cols-2"><label class="flex items-start gap-3"><input v-model="itemForm.care_requires_allergy_check" type="checkbox" class="mt-0.5 rounded border-input text-primary"><span><span class="block text-sm font-bold text-foreground">Vérifier les allergies</span><span class="text-xs text-muted-foreground">Recommandation affichée dans la fiche Soins.</span></span></label><label class="flex items-start gap-3"><input v-model="itemForm.care_recommends_vitals" type="checkbox" class="mt-0.5 rounded border-input text-primary"><span><span class="block text-sm font-bold text-foreground">Relever les constantes</span><span class="text-xs text-muted-foreground">Recommandation affichée à l’infirmier.</span></span></label></div>

                    <div v-if="itemForm.billable" class="mt-4 rounded border border-border p-4"><label class="mb-1.5 block text-sm font-bold text-foreground">Politique Avantage Personnel</label><select v-model="itemForm.staff_coverage_policy" class="h-9 w-full max-w-xl rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><option v-for="policy in options.staff_coverage_policies" :key="policy.value" :value="policy.value">{{ policy.label }}</option></select><p class="mt-1 text-xs text-muted-foreground">Le crédit Bloc dépend uniquement de cette classification explicite, jamais du nom, du code ou du module.</p><p v-if="itemForm.errors.staff_coverage_policy" class="mt-1 text-xs text-red-600">{{ itemForm.errors.staff_coverage_policy }}</p></div>

                    <div v-if="!editingItem && selectedType?.billable" class="mt-5 rounded border border-border p-4"><div class="mb-3"><h3 class="text-sm font-bold text-foreground">Tarifs initiaux</h3><p class="mt-0.5 text-xs text-muted-foreground">Le tarif standard est obligatoire. Le tarif mutuelle reste distinct et ne bénéficie d’aucun remplacement automatique.</p></div><div class="grid gap-4 md:grid-cols-3"><div><label class="mb-1.5 block text-sm font-medium text-foreground">Sans mutuelle (Ar) <span class="text-red-500">*</span></label><input v-model="itemForm.tariff_amount" type="number" min="1" step="1" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><p v-if="itemForm.errors.tariff_amount" class="mt-1 text-xs text-red-600">{{ itemForm.errors.tariff_amount }}</p></div><div><label class="mb-1.5 block text-sm font-medium text-foreground">Mutuelle (Ar)</label><input v-model="itemForm.mutual_tariff_amount" type="number" min="1" step="1" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><p v-if="itemForm.errors.mutual_tariff_amount" class="mt-1 text-xs text-red-600">{{ itemForm.errors.mutual_tariff_amount }}</p></div><div><label class="mb-1.5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></label><input v-model="itemForm.tariff_reason" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary" placeholder="Tarif initial validé"><p v-if="itemForm.errors.tariff_reason" class="mt-1 text-xs text-red-600">{{ itemForm.errors.tariff_reason }}</p></div></div></div>
                </div>
                <footer class="flex justify-end gap-3 border-t border-border px-5 py-4"><Button type="button" size="rg" variant="white-outline" @click="closeItemModal">Annuler</Button><Button size="rg" :disabled="itemForm.processing"><Check class="h-4 w-4" />{{ editingItem ? 'Enregistrer' : 'Créer sur le site' }}</Button></footer>
            </form>
        </div>

        <div v-if="tariffTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeTariff">
            <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg border border-border bg-card shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"><div><h2 class="text-lg font-bold text-foreground">Tarifs · {{ tariffTarget.name }}</h2><p class="mt-1 text-xs text-muted-foreground">{{ tariffTarget.code }} · {{ selectedSite.site.name }} · toute modification crée une nouvelle version.</p></div><button type="button" class="text-muted-foreground hover:text-foreground" @click="closeTariff"><X class="h-5 w-5" /></button></header>
                <div class="overflow-y-auto p-5">
                    <div class="inline-flex rounded border border-border bg-muted p-1"><button v-for="category in options.tariff_categories" :key="category.value" type="button" :class="['rounded px-4 py-2 text-sm font-bold', tariffForm.tariff_category === category.value ? 'bg-card text-primary shadow-sm ' : 'text-muted-foreground']" @click="chooseTariffCategory(category.value)">{{ category.label }}</button></div>
                    <form class="mt-4 rounded border border-border p-4" @submit.prevent="submitTariff"><div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_auto] md:items-end"><div><label class="mb-1.5 block text-sm font-medium text-foreground">Nouveau tarif (Ar) <span class="text-red-500">*</span></label><input v-model="tariffForm.tariff_amount" type="number" min="1" step="1" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><p v-if="tariffForm.errors.tariff_amount" class="mt-1 text-xs text-red-600">{{ tariffForm.errors.tariff_amount }}</p></div><div><label class="mb-1.5 block text-sm font-medium text-foreground">Motif du changement <span class="text-red-500">*</span></label><input v-model="tariffForm.reason" class="h-9 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary" placeholder="Décision tarifaire validée"><p v-if="tariffForm.errors.reason" class="mt-1 text-xs text-red-600">{{ tariffForm.errors.reason }}</p></div><Button size="rg" :disabled="tariffForm.processing"><Check class="h-4 w-4" />Enregistrer</Button></div></form>
                    <div class="mt-5 overflow-hidden rounded border border-border"><div class="flex items-center justify-between border-b border-border bg-muted px-4 py-3"><div><h3 class="text-sm font-bold text-foreground">Historique {{ tariffForm.tariff_category === 'MUTUAL' ? 'Mutuelle' : 'Sans mutuelle' }}</h3><p class="mt-0.5 text-xs text-muted-foreground">Les montants précédents restent intacts pour les anciennes factures.</p></div><button v-if="currentTariff && can('catalog.tariffs.archive')" type="button" class="text-xs font-bold text-red-600" @click="requestArchiveTariff">Suspendre le tarif actif</button></div><table class="w-full text-sm"><thead class="text-[10px] uppercase tracking-wide text-muted-foreground"><tr><th class="px-4 py-2 text-start">Montant</th><th class="px-4 py-2 text-start">Période</th><th class="px-4 py-2 text-start">Motif</th><th class="px-4 py-2 text-start">État</th></tr></thead><tbody class="divide-y divide-border"><tr v-for="tariff in tariffHistory" :key="tariff.uuid"><td class="px-4 py-2.5 font-bold text-foreground">{{ formatMoney(tariff.amount, tariff.currency) }}</td><td class="px-4 py-2.5 text-xs text-muted-foreground">{{ formatDateTime(tariff.effective_from) }}<span v-if="tariff.effective_until"> → {{ formatDateTime(tariff.effective_until) }}</span></td><td class="px-4 py-2.5 text-xs text-muted-foreground">{{ tariff.change_reason }}</td><td class="px-4 py-2.5"><span :class="['text-xs font-bold', tariff.current ? 'text-emerald-600' : 'text-muted-foreground']">{{ tariff.current ? 'Actif' : 'Historique' }}</span></td></tr><tr v-if="!tariffHistory.length"><td colspan="4" class="px-4 py-8 text-center text-xs text-muted-foreground">Aucun tarif enregistré dans cette catégorie.</td></tr></tbody></table></div>
                </div>
            </section>
        </div>

        <div v-if="confirmation" class="fixed inset-0 z-[1300] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true">
            <form class="w-full max-w-md rounded-lg border border-border bg-card p-5 shadow-xl" @submit.prevent="submitConfirmation"><div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-red-50 text-red-600 dark:bg-red-950/30"><Archive class="h-4.5 w-4.5" /></span><div><h2 class="text-base font-bold text-foreground">{{ confirmation.mode === 'item' ? 'Archiver cette désignation ?' : 'Suspendre ce tarif ?' }}</h2><p class="mt-1 text-sm leading-5 text-muted-foreground">{{ confirmation.mode === 'item' ? 'Elle ne sera plus proposée pour les nouveaux passages. Les factures et passages existants restent inchangés.' : 'Aucun tarif de remplacement ne sera inventé. Cette catégorie restera non configurée jusqu’à une nouvelle décision.' }}</p></div></div><label class="mb-1.5 mt-5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></label><textarea v-model="confirmationForm.reason" rows="3" class="w-full rounded border border-border bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-primary" placeholder="Précisez la décision"></textarea><p v-if="confirmationForm.errors.reason" class="mt-1 text-xs text-red-600">{{ confirmationForm.errors.reason }}</p><div class="mt-5 flex justify-end gap-3"><Button size="rg" variant="white-outline" type="button" @click="closeConfirmation">Annuler</Button><Button size="rg" variant="danger" :disabled="confirmationForm.processing"><Archive class="h-4 w-4" />Confirmer</Button></div></form>
        </div>
    </div>
</template>
