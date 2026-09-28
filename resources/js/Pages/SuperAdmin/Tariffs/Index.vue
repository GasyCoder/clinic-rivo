<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Ambulance,
    Archive,
    Baby,
    Bandage,
    Ban,
    BedDouble,
    Building2,
    CircleAlert,
    CircleCheck,
    CircleHelp,
    ConciergeBell,
    Copy,
    Download,
    Eye,
    FileSpreadsheet,
    FlaskConical,
    Hash,
    HeartPulse,
    History,
    Landmark,
    LayoutGrid,
    ListChecks,
    ListTree,
    Pencil,
    Percent,
    Pill,
    Plus,
    Receipt,
    RotateCcw,
    ScanLine,
    Scissors,
    Search,
    Server,
    ShieldCheck,
    Smile,
    Stethoscope,
    TriangleAlert,
    Upload,
    UserCog,
    Users,
    UsersRound,
    X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Switch from '@/Components/Shadcn/Switch.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import SettingsSiteSwitcher from '@/Components/Settings/SettingsSiteSwitcher.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import FormError from '@/Components/UI/FormError.vue';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/money';
import {
    IMAGING,
    IMAGING_UNCLASSIFIED,
    analysesUrl,
    catalogAttention,
    catalogGroupKey,
    catalogGroups,
    disciplineKey,
    disciplineLabel,
    duplicateNames,
    groupModule,
    laboratoryDisciplines,
    matchesAttention,
} from '@/utilities/catalogGroups';

/**
 * Tarifs & mutuelles d'un site (ADR-044, ADR-045, ADR-047) : les désignations et
 * leurs deux grilles, puis les mutuelles et leur taux. Tout passe par l'API du
 * site choisi ; l'écran ne décide rien, chaque écriture est revérifiée et
 * auditée sur le site destinataire.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    selectedSiteCode: { type: String, default: null },
});

const { can } = usePermissions();
const firstOnlineSite = props.sites.find((site) => site.ok)?.site.code;
const requestedSite = props.sites.find((site) => site.site.code === props.selectedSiteCode)?.site.code;
const selectedSiteCode = ref(requestedSite ?? firstOnlineSite ?? props.sites[0]?.site.code);
// L'adresse garde ce que l'on regarde (`?site=A&module=LABORATORY&q=NFS`) :
// le catalogue des analyses y renvoie, et une actualisation ne perd rien.
// `usePage().url` existe aussi au rendu serveur : aucune lecture du navigateur.
const initialQuery = new URL(usePage().url, 'http://rivo.local').searchParams;
const search = ref(initialQuery.get('q') ?? '');
const typeFilter = ref('');
/** Le groupe choisi : un domaine, ou une famille d'imagerie (`IMAGING:ULTRASOUND`). */
const groupFilter = ref(initialQuery.get('module') ?? '');
/** Au Laboratoire : la discipline, lue sur l'analyse racine (ADR-063). */
const disciplineFilter = ref('');
/** Un point d'attention choisi : STAFF, DUPLICATES, IMAGING_UNCLASSIFIED, LAB_NO_ANALYSIS. */
const attentionFilter = ref('');
/** La carte-compteur choisie : chaque carte est un filtre (ACTIVE par défaut). */
const itemView = ref('ACTIVE');
const tariffFilter = ref('ALL');
const workspaceView = ref('TARIFFS');
const organizationSearch = ref('');
const organizationView = ref('ACTIVE');
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
const importOpen = ref(false);
const SELECTION_LIMIT = 100;

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
    types: [], modules: [], imaging_modalities: [], routing_modes: [], tariff_categories: [], staff_coverage_policies: [],
});
const allItems = computed(() => siteData.value.items ?? []);
const duplicates = computed(() => duplicateNames(allItems.value));
const attention = computed(() => catalogAttention(allItems.value));

/** Ce que chaque carte-compteur garde — les mêmes règles que les compteurs servis par le site. */
const VIEW_MATCHERS = {
    ALL: () => true,
    ACTIVE: (item) => ! item.archived,
    BILLABLE: (item) => ! item.archived && item.billable,
    MISSING_STANDARD: (item) => ! item.archived && item.billable && ! item.current_standard_tariff,
    MISSING_MUTUAL: (item) => ! item.archived && item.billable && ! item.current_mutual_tariff,
    ARCHIVED: (item) => item.archived,
};
/**
 * Toutes les règles sauf le groupe : ce que chaque puce de domaine compte est
 * exactement ce qu'un clic dessus afficherait (comme les compteurs de l'ADR-119).
 */
const baseItems = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();
    const matchesView = VIEW_MATCHERS[itemView.value] ?? VIEW_MATCHERS.ALL;

    return allItems.value.filter((item) => {
        const matchesSearch = ! needle || [item.name, item.code, item.description]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase().includes(needle));
        const matchesType = ! typeFilter.value || item.type === typeFilter.value;
        const matchesTariff = tariffFilter.value === 'ALL' || item.billable;

        return matchesSearch && matchesType && matchesView(item) && matchesTariff
            && matchesAttention(item, attentionFilter.value, duplicates.value);
    });
});
const groups = computed(() => catalogGroups(baseItems.value, options.value.modules));
const groupOrder = computed(() => new Map(groups.value.map((group, index) => [group.key, index])));
const inGroup = (item, key) => (key.includes(':') ? catalogGroupKey(item) === key : item.module === key);
const groupedItems = computed(() => (groupFilter.value
    ? baseItems.value.filter((item) => inGroup(item, groupFilter.value))
    : baseItems.value));
const disciplines = computed(() => (groupFilter.value === 'LABORATORY' ? laboratoryDisciplines(groupedItems.value) : []));
const items = computed(() => {
    const filtered = disciplineFilter.value
        ? groupedItems.value.filter((item) => disciplineKey(item) === disciplineFilter.value)
        : groupedItems.value;
    if (groupFilter.value) return filtered;

    // Sans groupe choisi, la liste se lit domaine par domaine, dans l'ordre des puces.
    const order = groupOrder.value;

    return [...filtered].sort((left, right) => (order.get(catalogGroupKey(left)) ?? 99) - (order.get(catalogGroupKey(right)) ?? 99));
});
/** Les lignes du tableau : un intitulé de domaine avant ses désignations quand tout est affiché. */
const tableRows = computed(() => {
    if (groupFilter.value) return items.value.map((item) => ({ key: item.uuid, item }));

    const rows = [];
    let current = null;
    const byKey = new Map(groups.value.map((group) => [group.key, group]));
    items.value.forEach((item) => {
        const key = catalogGroupKey(item);
        if (key !== current) {
            current = key;
            rows.push({ key: `group:${key}`, group: byKey.get(key) ?? { key, label: item.module_label } });
        }
        rows.push({ key: item.uuid, item });
    });

    return rows;
});
const allGroupsCount = computed(() => baseItems.value.length);
const selectedGroup = computed(() => groups.value.find((group) => group.key === groupFilter.value) ?? null);

const GROUP_ICONS = {
    RECEPTION: ConciergeBell,
    MEDICINE: Stethoscope,
    CARE: Bandage,
    LABORATORY: FlaskConical,
    'IMAGING:ULTRASOUND': ScanLine,
    'IMAGING:CARDIOLOGY': HeartPulse,
    [IMAGING_UNCLASSIFIED]: CircleHelp,
    PHARMACY: Pill,
    SURGERY: Scissors,
    ADMINISTRATION: Landmark,
    MATERNITY: Baby,
    HOSPITALIZATION: BedDouble,
    TRANSFER: Ambulance,
    PEDIATRICS: Smile,
    OPHTHALMOLOGY: Eye,
    FAMILY_PLANNING: Users,
};
const groupIcon = (key) => GROUP_ICONS[key] ?? GROUP_ICONS[groupModule(key)] ?? LayoutGrid;
const chooseGroup = (key) => {
    groupFilter.value = groupFilter.value === key ? '' : key;
    disciplineFilter.value = '';
};
const chooseDiscipline = (key) => {
    disciplineFilter.value = disciplineFilter.value === key ? '' : key;
};
const attentionPoints = computed(() => [
    {
        value: 'STAFF', count: attention.value.staffUnclassified, icon: UserCog,
        label: 'sans politique Personnel', hint: 'Un passage Personnel ne peut pas être facturé tant que la prestation n’est pas classée (ADR-052).',
    },
    {
        value: 'DUPLICATES', count: attention.value.duplicates, icon: Copy,
        label: 'portent le nom d’une autre', hint: 'Même nom dans le même domaine : deux prestations, deux tarifs, deux choix à la Réception.',
    },
    {
        value: 'IMAGING_UNCLASSIFIED', count: attention.value.imagingUnclassified, icon: CircleHelp,
        label: 'examens d’imagerie non classés', hint: 'Ni Échographie ni ECG : ils n’apparaissent dans aucun de ces deux onglets des demandes d’examens (ADR-106).',
    },
    {
        value: 'LAB_NO_ANALYSIS', count: attention.value.laboratoryWithoutAnalysis, icon: ListTree,
        label: 'analyses sans définition technique', hint: 'Aucune ligne au catalogue des analyses : le Laboratoire n’a rien où saisir un résultat (ADR-063).',
    },
].filter((point) => point.count > 0));
const chooseAttention = (value) => {
    attentionFilter.value = attentionFilter.value === value ? '' : value;
};
/** Les autres désignations au même nom, pour le survol du repère « doublon ». */
const duplicateTitle = (item) => `Même nom que ${duplicates.value.get(item.uuid)?.map((other) => other.code).join(', ')} dans ce domaine.`;

const typeOptions = computed(() => [{ value: '', label: 'Tous les types' }, ...options.value.types.map((type) => ({ value: type.value, label: type.label }))]);
const imagingModalityOptions = computed(() => [
    { value: '', label: 'Non classée' },
    ...(options.value.imaging_modalities ?? []),
]);
const TARIFF_FILTER_OPTIONS = [
    { value: 'ALL', label: 'Les deux grilles' },
    { value: 'STANDARD', label: 'Sans mutuelle' },
    { value: 'MUTUAL', label: 'Mutuelle' },
];
const tariffFilterLabel = computed(() => TARIFF_FILTER_OPTIONS.find((option) => option.value === tariffFilter.value)?.label ?? '');
const hasItemFilters = computed(() => Boolean(
    search.value || typeFilter.value || groupFilter.value || disciplineFilter.value
        || attentionFilter.value || tariffFilter.value !== 'ALL',
));
const resetItemFilters = () => {
    search.value = '';
    typeFilter.value = '';
    groupFilter.value = '';
    disciplineFilter.value = '';
    attentionFilter.value = '';
    tariffFilter.value = 'ALL';
};

/** Un compteur absent (droit manquant sur le site) s'écrit « — », jamais 0 (ADR-102). */
const countOf = (value) => (value === null || value === undefined ? '—' : value);
const itemTiles = computed(() => [
    { value: 'ACTIVE', label: 'Actives', hint: 'désignations en service', icon: ListChecks, tone: 'primary', count: countOf(summary.value.active), active: itemView.value === 'ACTIVE' },
    { value: 'BILLABLE', label: 'Facturables', hint: 'portent un tarif', icon: Receipt, tone: 'sky', count: countOf(summary.value.billable), active: itemView.value === 'BILLABLE' },
    {
        value: 'MISSING_STANDARD', label: 'Sans tarif standard', hint: 'à configurer', icon: CircleAlert, tone: 'amber',
        count: countOf(summary.value.without_standard_tariff), active: itemView.value === 'MISSING_STANDARD',
        filterable: summary.value.without_standard_tariff !== null,
    },
    {
        value: 'MISSING_MUTUAL', label: 'Sans tarif mutuelle', hint: 'à configurer', icon: CircleAlert, tone: 'amber',
        count: countOf(summary.value.without_mutual_tariff), active: itemView.value === 'MISSING_MUTUAL',
        filterable: summary.value.without_mutual_tariff !== null,
    },
    { value: 'ARCHIVED', label: 'Archivées', hint: 'restaurables', icon: Archive, tone: 'neutral', count: countOf(summary.value.archived), active: itemView.value === 'ARCHIVED' },
]);
// Recliquer une carte déjà choisie revient aux désignations actives.
const chooseItemView = (view) => {
    itemView.value = itemView.value === view && view !== 'ACTIVE' ? 'ACTIVE' : view;
};

const selectedItems = computed(() => items.value.filter((item) => selectedItemUuids.value.has(item.uuid)));
const selectedActiveItems = computed(() => selectedItems.value.filter((item) => ! item.archived));
const selectedArchivedItems = computed(() => selectedItems.value.filter((item) => item.archived));
const groupSelectionState = (key) => {
    const members = items.value.filter((item) => catalogGroupKey(item) === key);
    const chosen = members.filter((item) => selectedItemUuids.value.has(item.uuid)).length;
    if (! members.length || ! chosen) return false;

    return chosen === members.length ? true : 'indeterminate';
};
const toggleGroupSelection = (key) => {
    const members = items.value.filter((item) => catalogGroupKey(item) === key);
    const next = new Set(selectedItemUuids.value);
    if (groupSelectionState(key) === true) members.forEach((item) => next.delete(item.uuid));
    else members.forEach((item) => { if (next.size < SELECTION_LIMIT) next.add(item.uuid); });
    selectedItemUuids.value = next;
};
const itemsHeaderState = computed(() => {
    const chosen = items.value.filter((item) => selectedItemUuids.value.has(item.uuid)).length;
    if (! items.value.length || ! chosen) return false;

    return chosen === items.value.length ? true : 'indeterminate';
});
const canManageTariffs = computed(() => can('catalog.tariffs.create') || can('catalog.tariffs.update'));

const organizationsSummary = computed(() => siteData.value.mutual_organizations_summary ?? {
    active: 0,
    archived: 0,
    active_coverages: 0,
});
const organizations = computed(() => {
    const needle = organizationSearch.value.trim().toLocaleLowerCase();

    return (siteData.value.mutual_organizations ?? []).filter((organization) => {
        const matchesSearch = ! needle || organization.name.toLocaleLowerCase().includes(needle);
        const matchesView = organizationView.value === 'ALL'
            || (organizationView.value === 'ACTIVE' && organization.active)
            || (organizationView.value === 'ARCHIVED' && ! organization.active);

        return matchesSearch && matchesView;
    });
});
const organizationTiles = computed(() => [
    { value: 'ACTIVE', label: 'Mutuelles actives', hint: 'proposées à la Réception', icon: Building2, tone: 'primary', count: organizationsSummary.value.active, active: organizationView.value === 'ACTIVE' },
    { value: 'COVERAGES', label: 'Couvertures en cours', hint: 'passages couverts', icon: UsersRound, tone: 'emerald', count: organizationsSummary.value.active_coverages, filterable: false },
    { value: 'ARCHIVED', label: 'Archivées', hint: 'restaurables', icon: Archive, tone: 'neutral', count: organizationsSummary.value.archived, active: organizationView.value === 'ARCHIVED' },
]);
const chooseOrganizationView = (view) => {
    organizationView.value = organizationView.value === view && view !== 'ACTIVE' ? 'ACTIVE' : view;
};
const selectedOrganizations = computed(() => organizations.value.filter((organization) => selectedOrganizationUuids.value.has(organization.uuid)));
const selectedActiveOrganizations = computed(() => selectedOrganizations.value.filter((organization) => organization.active));
const selectedArchivedOrganizations = computed(() => selectedOrganizations.value.filter((organization) => ! organization.active));
const organizationsHeaderState = computed(() => {
    const chosen = selectedOrganizations.value.length;
    if (! organizations.value.length || ! chosen) return false;

    return chosen === organizations.value.length ? true : 'indeterminate';
});

const itemForm = useForm({
    site_code: selectedSiteCode.value,
    code: '',
    name: '',
    type: 'SERVICE',
    module: 'RECEPTION',
    imaging_modality: '',
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

/** `Select` échange des chaînes : un parcours non choisi (`null`) s'y lit « ». */
const routingModeModel = computed({
    get: () => itemForm.reception_routing_mode ?? '',
    set: (value) => { itemForm.reception_routing_mode = value || null; },
});
const routingModeOptions = computed(() => [{ value: '', label: 'Choisir le parcours' }, ...options.value.routing_modes]);

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
const importTemplateUrl = computed(() => (workspaceView.value === 'ORGANIZATIONS'
    ? '/super-admin/workspaces/tariffs/mutual-organizations/import-template'
    : '/super-admin/workspaces/tariffs/import-template'));
const canExport = computed(() => (workspaceView.value === 'TARIFFS' ? can('catalog.tariffs.export') : can('mutual_organizations.export')));
const canImport = computed(() => (workspaceView.value === 'TARIFFS' ? can('catalog.tariffs.import') : can('mutual_organizations.import')));

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
const clearItemSelection = () => { selectedItemUuids.value = new Set(); };
const clearOrganizationSelection = () => { selectedOrganizationUuids.value = new Set(); };

const selectedType = computed(() => options.value.types.find((type) => type.value === itemForm.type));
const isCareService = computed(() => itemForm.type === 'SERVICE' && itemForm.module === 'CARE');
const currentTariff = computed(() => {
    if (! tariffTarget.value) return null;

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
    importOpen.value = false;
    importForm.clearErrors();
    importForm.file = null;
});

// Le groupe et la recherche suivent l'adresse, sans nouvelle visite.
watch([groupFilter, search], ([group, needle]) => {
    const url = new URL(window.location.href);
    group ? url.searchParams.set('module', group) : url.searchParams.delete('module');
    needle.trim() ? url.searchParams.set('q', needle.trim()) : url.searchParams.delete('q');
    window.history.replaceState(window.history.state, '', url);
});
// Un groupe qui n'existe plus (autre site, filtre) n'enferme pas l'écran sur une liste vide.
watch(groups, (present) => {
    if (groupFilter.value && ! present.some((group) => group.key === groupFilter.value) && ! attentionFilter.value) {
        groupFilter.value = '';
        disciplineFilter.value = '';
    }
});

const selectSite = (code) => {
    selectedSiteCode.value = code;
    itemForm.site_code = code;
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

const resetItemForm = () => {
    itemForm.reset();
    itemForm.clearErrors();
    itemForm.site_code = selectedSiteCode.value;
    itemForm.type = 'SERVICE';
    // Une désignation créée depuis un domaine en hérite, famille d'imagerie comprise.
    itemForm.module = groupFilter.value ? groupModule(groupFilter.value) : 'RECEPTION';
    itemForm.imaging_modality = groupFilter.value.startsWith(`${IMAGING}:`) && groupFilter.value !== IMAGING_UNCLASSIFIED
        ? groupFilter.value.split(':')[1]
        : '';
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
        imaging_modality: item.imaging_modality ?? '',
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
            imaging_modality: data.module === IMAGING ? (data.imaging_modality || null) : null,
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
        imaging_modality: data.module === IMAGING ? (data.imaging_modality || null) : null,
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
    const endpoint = workspaceView.value === 'ORGANIZATIONS'
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

const TH = 'h-10 px-3 text-start align-middle text-xs font-medium text-muted-foreground';
const TD = 'px-3 py-3 align-middle';
</script>

<template>
    <Head title="Tarifs & mutuelles" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Super Administration · Référentiels"
            title="Tarifs & mutuelles"
            description="Désignations, grilles tarifaires et mutuelles propres à chaque clinique. Chaque écriture part par l’API du site choisi et y est auditée."
            :icon="ListChecks"
        >
            <template #actions>
                <Button v-if="canExport" as="a" :href="exportUrl" variant="outline" :disabled="! selectedSite?.ok">
                    <Download class="h-4 w-4" />Exporter Excel
                </Button>
                <Button v-if="canImport" type="button" variant="outline" :disabled="! selectedSite?.ok" @click="openImport">
                    <Upload class="h-4 w-4" />Importer Excel
                </Button>
                <Button v-if="workspaceView === 'TARIFFS' && can('catalog.items.create')" type="button" :disabled="! selectedSite?.ok" @click="openCreate">
                    <Plus class="h-4 w-4" />Nouvelle désignation
                </Button>
                <Button v-else-if="workspaceView === 'ORGANIZATIONS' && can('mutual_organizations.create')" type="button" :disabled="! selectedSite?.ok" @click="openCreateOrganization">
                    <Plus class="h-4 w-4" />Nouvelle mutuelle
                </Button>
            </template>
        </PageHeader>

        <!-- Le site, puis la section : les deux choix qui décident de tout l'écran. -->
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <SettingsSiteSwitcher v-if="sites.length" :targets="sites" :model-value="selectedSiteCode" label="Site affiché" @update:model-value="selectSite" />
            <div class="flex flex-wrap items-center gap-3">
                <Tabs v-if="selectedSite?.ok" v-model="workspaceView">
                    <TabsList aria-label="Sections du référentiel">
                        <TabsTrigger value="TARIFFS"><ListChecks class="h-4 w-4" />Désignations & tarifs</TabsTrigger>
                        <TabsTrigger v-if="can('mutual_organizations.view')" value="ORGANIZATIONS">
                            <Building2 class="h-4 w-4" />Mutuelles
                            <span class="rounded bg-muted px-1.5 text-[10px] font-semibold tabular-nums text-muted-foreground">{{ organizationsSummary.active }}</span>
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
                <Badge variant="outline" class="hidden md:inline-flex" title="Aucune base clinique n’est lue directement : le portail passe par l’API du site."><ShieldCheck class="h-3.5 w-3.5" />Écritures auditées sur le site</Badge>
            </div>
        </div>

        <Card v-if="! selectedSite?.ok" class="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
            <span class="grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Server class="h-6 w-6" /></span>
            <h2 class="mt-3 text-sm font-bold text-foreground">API indisponible pour {{ selectedSite?.site.name ?? 'ce site' }}</h2>
            <p class="mt-1 max-w-xl text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p>
            <p class="mt-3 text-xs text-muted-foreground">Les autres sites restent utilisables et aucune base clinique n’est accédée directement.</p>
        </Card>

        <!-- ------------------------------------------------------------ -->
        <!-- Désignations & tarifs                                         -->
        <!-- ------------------------------------------------------------ -->
        <template v-else-if="workspaceView === 'TARIFFS'">
            <QueueCounters :tiles="itemTiles" class="lg:grid-cols-5" @select="chooseItemView" />

            <!-- Ce qui reste à régler sur ce site : chaque point est un filtre, rien n'est corrigé à la place du Super Admin. -->
            <section v-if="attentionPoints.length" class="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20" aria-labelledby="tariffs-attention-title">
                <div class="flex flex-col gap-2.5 lg:flex-row lg:items-center">
                    <p id="tariffs-attention-title" class="flex shrink-0 items-center gap-2 text-sm font-semibold text-amber-800 dark:text-amber-200">
                        <TriangleAlert class="h-4 w-4" />À régler sur {{ selectedSite.site.name }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="point in attentionPoints"
                            :key="point.value"
                            type="button"
                            :title="point.hint"
                            :aria-pressed="attentionFilter === point.value"
                            :class="cn(
                                'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                attentionFilter === point.value
                                    ? 'border-amber-500 bg-amber-100 text-amber-900 dark:border-amber-600 dark:bg-amber-900/50 dark:text-amber-100'
                                    : 'border-amber-200 bg-card text-amber-800 hover:border-amber-400 dark:border-amber-900 dark:text-amber-200',
                            )"
                            @click="chooseAttention(point.value)"
                        >
                            <component :is="point.icon" class="h-3.5 w-3.5" />
                            <span class="font-bold tabular-nums">{{ point.count }}</span>{{ point.label }}
                            <X v-if="attentionFilter === point.value" class="h-3 w-3" />
                        </button>
                    </div>
                </div>
            </section>

            <Card class="overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-border p-4 xl:flex-row xl:items-center xl:justify-between">
                    <div class="relative w-full xl:max-w-xs">
                        <IconInput v-model="search" :icon="Search" type="search" placeholder="Code ou désignation" aria-label="Rechercher une désignation" class="pe-9" />
                        <button v-if="search" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="search = ''">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2 xl:flex xl:items-center">
                        <Select v-model="typeFilter" :options="typeOptions" aria-label="Filtrer par type" class="w-full xl:w-44" />
                        <Select v-model="tariffFilter" :options="TARIFF_FILTER_OPTIONS" :icon="Receipt" aria-label="Grille affichée" class="w-full xl:w-48" />
                        <Button v-if="hasItemFilters" type="button" variant="ghost" size="sm" @click="resetItemFilters"><X class="h-4 w-4" />Effacer</Button>
                    </div>
                </div>

                <!-- Domaine par domaine : l'Imagerie se sépare en Échographie et ECG sur la famille réglée au catalogue. -->
                <nav class="border-b border-border bg-muted/20 px-4 py-3" aria-label="Domaines des désignations">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            :aria-pressed="! groupFilter"
                            :class="cn(
                                'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                ! groupFilter ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                            )"
                            @click="chooseGroup('')"
                        >
                            <LayoutGrid class="h-4 w-4" />Tous
                            <span class="rounded bg-muted px-1.5 text-xs font-semibold tabular-nums text-muted-foreground">{{ allGroupsCount }}</span>
                        </button>
                        <button
                            v-for="group in groups"
                            :key="group.key"
                            type="button"
                            :aria-pressed="groupFilter === group.key"
                            :title="group.billable ? `${group.priced} sur ${group.billable} ${group.billable > 1 ? 'désignations facturables ont' : 'désignation facturable a'} un tarif sans mutuelle` : 'Aucune désignation facturable'"
                            :class="cn(
                                'group inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                groupFilter === group.key
                                    ? (group.unclassified ? 'border-amber-500 bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200' : 'border-primary bg-primary/10 text-primary')
                                    : (group.unclassified ? 'border-amber-200 bg-card text-amber-700 hover:border-amber-400 dark:border-amber-900 dark:text-amber-300' : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground'),
                            )"
                            @click="chooseGroup(group.key)"
                        >
                            <component :is="groupIcon(group.key)" class="h-4 w-4" />{{ group.label }}
                            <span class="rounded bg-muted px-1.5 text-xs font-semibold tabular-nums text-muted-foreground">{{ group.count }}</span>
                            <span
                                v-if="group.billable"
                                :class="cn('hidden items-center gap-0.5 text-[11px] tabular-nums sm:inline-flex', group.priced === group.billable ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400')"
                            ><CircleCheck v-if="group.priced === group.billable" class="h-3 w-3" />{{ group.priced === group.billable ? 'tout tarifé' : `${group.priced}/${group.billable} tarifés` }}</span>
                        </button>
                    </div>

                    <!-- Au Laboratoire, la discipline : celle de l'analyse racine au catalogue des analyses. -->
                    <div v-if="disciplines.length > 1" class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-border/70 pt-3" aria-label="Disciplines du Laboratoire">
                        <span class="me-1 text-xs font-medium text-muted-foreground">Discipline</span>
                        <button
                            v-for="discipline in disciplines"
                            :key="discipline.key"
                            type="button"
                            :title="discipline.title"
                            :aria-pressed="disciplineFilter === discipline.key"
                            :class="cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                disciplineFilter === discipline.key ? 'border-primary bg-primary/10 font-semibold text-primary' : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                            )"
                            @click="chooseDiscipline(discipline.key)"
                        >
                            {{ discipline.label }}<span class="tabular-nums opacity-70">{{ discipline.count }}</span>
                        </button>
                    </div>
                    <p v-if="selectedGroup?.unclassified" class="mt-2.5 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-300">
                        <CircleHelp class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        Ces examens n’ont pas de famille : ils n’apparaissent ni dans l’onglet Échographie ni dans l’onglet ECG des demandes d’examens. Modifiez-les pour choisir leur famille — elle n’est jamais déduite du code.
                    </p>
                </nav>

                <div v-if="selectedItems.length" class="flex flex-col gap-3 border-b border-border bg-primary/5 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <Badge>{{ selectedItems.length }}</Badge>
                        <div>
                            <p class="text-sm font-semibold text-foreground">{{ selectedItems.length > 1 ? 'désignations sélectionnées' : 'désignation sélectionnée' }}</p>
                            <p class="text-xs text-muted-foreground">Opération atomique sur {{ selectedSite.site.name }} · {{ SELECTION_LIMIT }} au plus</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="can('catalog.tariffs.export')" as="a" :href="exportUrl" size="sm" variant="outline"><Download class="h-4 w-4" />Exporter</Button>
                        <Button v-if="can('catalog.items.delete') && selectedActiveItems.length" type="button" size="sm" variant="danger-outline" @click="openBulkAction('ITEMS', 'ARCHIVE')"><Archive class="h-4 w-4" />Archiver ({{ selectedActiveItems.length }})</Button>
                        <Button v-if="can('catalog.items.restore') && selectedArchivedItems.length" type="button" size="sm" variant="outline" @click="openBulkAction('ITEMS', 'RESTORE')"><RotateCcw class="h-4 w-4" />Restaurer ({{ selectedArchivedItems.length }})</Button>
                        <Button type="button" size="sm" variant="ghost" @click="clearItemSelection">Désélectionner</Button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1080px] text-sm">
                        <thead class="border-b border-border bg-muted/40">
                            <tr>
                                <th :class="cn(TH, 'w-10 ps-4')">
                                    <Checkbox :model-value="itemsHeaderState" :disabled="! items.length" aria-label="Sélectionner toutes les désignations affichées" @update:model-value="toggleVisibleItemSelection" />
                                </th>
                                <th :class="TH">Désignation</th>
                                <th :class="TH">Détail</th>
                                <th :class="TH">Parcours Réception</th>
                                <th v-if="tariffFilter !== 'MUTUAL'" :class="cn(TH, 'text-end')">Sans mutuelle</th>
                                <th v-if="tariffFilter !== 'STANDARD'" :class="cn(TH, 'text-end')">Mutuelle</th>
                                <th :class="TH">État</th>
                                <th :class="cn(TH, 'pe-4 text-end')">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <template v-for="row in tableRows" :key="row.key">
                            <tr v-if="row.group" class="bg-muted/50">
                                <td :class="cn(TD, 'ps-4 py-2')">
                                    <Checkbox :model-value="groupSelectionState(row.group.key)" :aria-label="`Sélectionner les désignations ${row.group.label}`" @update:model-value="toggleGroupSelection(row.group.key)" />
                                </td>
                                <td :colspan="tariffFilter === 'ALL' ? 7 : 6" class="px-3 py-2">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <span :class="cn('inline-flex items-center gap-2 text-sm font-semibold', row.group.unclassified ? 'text-amber-700 dark:text-amber-300' : 'text-foreground')">
                                            <component :is="groupIcon(row.group.key)" class="h-4 w-4" />{{ row.group.label }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">{{ plural(row.group.count, 'désignation', 'désignations') }}<template v-if="row.group.billable"> · {{ row.group.priced }}/{{ row.group.billable }} tarifées</template></span>
                                        <button type="button" class="ms-auto text-xs font-medium text-primary hover:underline" @click="chooseGroup(row.group.key)">Voir seulement ce domaine</button>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-else
                                :class="cn('transition-colors hover:bg-muted/40', selectedItemUuids.has(row.item.uuid) && 'bg-primary/5', row.item.archived && 'text-muted-foreground')"
                            >
                                <template v-for="item in [row.item]" :key="item.uuid">
                                <td :class="cn(TD, 'ps-4')">
                                    <Checkbox :model-value="selectedItemUuids.has(item.uuid)" :aria-label="`Sélectionner ${item.name}`" @update:model-value="toggleItemSelection(item.uuid)" />
                                </td>
                                <td :class="TD">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span :class="cn('font-semibold', item.archived ? 'text-muted-foreground line-through' : 'text-foreground')">{{ item.name }}</span>
                                        <span class="rounded border border-border bg-muted/60 px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted-foreground">{{ item.code }}</span>
                                        <button
                                            v-if="duplicates.has(item.uuid)"
                                            type="button"
                                            :title="`${duplicateTitle(item)} Cliquer pour les afficher ensemble.`"
                                            class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 hover:border-amber-400 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                                            @click="search = item.name"
                                        ><Copy class="h-3 w-3" />Nom en double ×{{ duplicates.get(item.uuid).length + 1 }}</button>
                                    </div>
                                    <p class="mt-0.5 line-clamp-1 max-w-md text-xs text-muted-foreground">{{ item.description || `Unité : ${item.unit}` }}</p>
                                </td>
                                <!-- Le domaine est déjà dit par l'intitulé du groupe ou la puce choisie : la cellule ne dit que le détail. -->
                                <td :class="TD">
                                    <p class="whitespace-nowrap text-xs text-muted-foreground">{{ item.type_label }} · {{ item.unit }}</p>
                                    <!-- Laboratoire : la discipline et les analyses techniques que porte la prestation (ADR-063). -->
                                    <div v-if="item.module === 'LABORATORY'" class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                        <span v-if="item.analysis_discipline" class="text-muted-foreground" :title="item.analysis_discipline">{{ disciplineLabel(disciplineKey(item)) }}</span>
                                        <Link
                                            v-if="item.analyses_count && can('analysis_catalog.view')"
                                            :href="analysesUrl(selectedSiteCode, item)"
                                            class="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                            :title="`Ouvrir les ${item.analyses_count} lignes techniques de cette prestation au catalogue des analyses`"
                                        ><ListTree class="h-3 w-3" />{{ plural(item.analyses_count, 'analyse', 'analyses') }}</Link>
                                        <span v-else-if="item.analyses_count" class="text-muted-foreground">{{ plural(item.analyses_count, 'analyse', 'analyses') }}</span>
                                        <Badge v-else variant="warning" class="text-[10px]" title="Aucune ligne au catalogue des analyses : le Laboratoire n’a rien où saisir un résultat."><ListTree class="h-3 w-3" />Sans analyse</Badge>
                                    </div>
                                    <!-- Imagerie : la famille réglée au catalogue, jamais déduite du code (ADR-106). -->
                                    <div v-else-if="item.module === 'IMAGING' && ! item.imaging_modality" class="mt-1">
                                        <button
                                            v-if="! item.archived && can('catalog.items.update')"
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 hover:border-amber-400 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                                            @click="openEdit(item)"
                                        ><CircleHelp class="h-3 w-3" />Famille à choisir</button>
                                        <span v-else class="text-xs text-amber-700 dark:text-amber-300">Famille non classée</span>
                                    </div>
                                </td>
                                <td :class="TD">
                                    <Badge v-if="item.reception_selectable" variant="secondary" class="whitespace-nowrap">{{ item.reception_routing_label }}</Badge>
                                    <span v-else class="text-xs text-muted-foreground">Non proposée</span>
                                </td>
                                <td v-for="grid in ['STANDARD', 'MUTUAL'].filter((value) => tariffFilter === 'ALL' || tariffFilter === value)" :key="grid" :class="cn(TD, 'text-end')">
                                    <template v-for="current in [grid === 'MUTUAL' ? item.current_mutual_tariff : item.current_standard_tariff]" :key="grid">
                                        <Button
                                            v-if="current && ! item.archived && canManageTariffs"
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="-me-2 font-semibold tabular-nums text-foreground"
                                            :title="`Modifier le tarif ${grid === 'MUTUAL' ? 'mutuelle' : 'sans mutuelle'}`"
                                            @click="openTariff(item, grid)"
                                        >{{ formatMoney(current.amount, current.currency) }}</Button>
                                        <span v-else-if="current" class="font-semibold tabular-nums text-foreground">{{ formatMoney(current.amount, current.currency) }}</span>
                                        <Button v-else-if="item.billable && ! item.archived && canManageTariffs" type="button" variant="warning-outline" size="xs" @click="openTariff(item, grid)"><Plus class="h-3.5 w-3.5" />Configurer</Button>
                                        <span v-else class="text-xs text-muted-foreground">{{ item.billable ? 'Non configuré' : (grid === 'STANDARD' ? 'Non facturable' : '—') }}</span>
                                    </template>
                                </td>
                                <td :class="TD">
                                    <Badge v-if="item.archived" variant="outline"><Archive class="h-3 w-3" />Archivée</Badge>
                                    <Badge v-else variant="success"><CircleCheck class="h-3 w-3" />Active</Badge>
                                </td>
                                <td :class="cn(TD, 'pe-4 text-end')">
                                    <div class="inline-flex items-center gap-0.5">
                                        <Button v-if="! item.archived && can('catalog.items.update')" type="button" variant="ghost" icon size="sm" :title="`Modifier ${item.name}`" :aria-label="`Modifier ${item.name}`" @click="openEdit(item)"><Pencil class="h-4 w-4" /></Button>
                                        <Button v-if="! item.archived && item.billable && canManageTariffs" type="button" variant="ghost" icon size="sm" :title="`Tarifs et historique de ${item.name}`" :aria-label="`Tarifs et historique de ${item.name}`" @click="openTariff(item, tariffFilter === 'MUTUAL' ? 'MUTUAL' : 'STANDARD')"><History class="h-4 w-4" /></Button>
                                        <Button v-if="! item.archived && can('catalog.items.delete')" type="button" variant="ghost" icon size="sm" class="hover:text-destructive" :title="`Archiver ${item.name}`" :aria-label="`Archiver ${item.name}`" @click="requestArchiveItem(item)"><Archive class="h-4 w-4" /></Button>
                                        <Button v-if="item.archived && can('catalog.items.restore')" type="button" variant="outline" size="xs" @click="restoreItem(item)"><RotateCcw class="h-3.5 w-3.5" />Restaurer</Button>
                                    </div>
                                </td>
                                </template>
                            </tr>
                            </template>
                            <tr v-if="! items.length">
                                <td :colspan="tariffFilter === 'ALL' ? 8 : 7" class="px-5 py-14 text-center">
                                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><ListChecks class="h-6 w-6" /></span>
                                    <p class="mt-3 text-sm font-semibold text-foreground">Aucune désignation ne correspond</p>
                                    <p class="mt-1 text-xs text-muted-foreground">Changez de carte, de filtre ou de recherche.</p>
                                    <Button v-if="hasItemFilters" type="button" variant="outline" size="sm" class="mt-3" @click="resetItemFilters"><X class="h-4 w-4" />Effacer les filtres</Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-xs text-muted-foreground">
                    <span>{{ plural(items.length, 'désignation affichée', 'désignations affichées') }}<template v-if="selectedGroup"> · {{ selectedGroup.label }}</template> · {{ tariffFilterLabel }}</span>
                    <span>Site {{ selectedSite.site.name }} · données API</span>
                </div>
            </Card>
        </template>

        <!-- ------------------------------------------------------------ -->
        <!-- Mutuelles                                                     -->
        <!-- ------------------------------------------------------------ -->
        <template v-else>
            <QueueCounters :tiles="organizationTiles" class="lg:grid-cols-3" @select="chooseOrganizationView" />

            <Card class="overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative w-full sm:max-w-sm">
                        <IconInput v-model="organizationSearch" :icon="Search" type="search" placeholder="Rechercher une mutuelle" aria-label="Rechercher une mutuelle" class="pe-9" />
                        <button v-if="organizationSearch" type="button" class="absolute inset-y-0 end-0 grid w-9 place-items-center text-muted-foreground hover:text-foreground" aria-label="Effacer la recherche" @click="organizationSearch = ''">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <p class="flex items-start gap-2 text-xs leading-5 text-muted-foreground sm:max-w-xl">
                        <CircleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>« Sans mutuelle » est le tarif standard et « Avantage Personnel » relève des RH : ce ne sont pas des mutuelles. Les partenaires (ISPSG, médecins extérieurs…) ont leur module Partenaires.</span>
                    </p>
                </div>

                <div v-if="selectedOrganizations.length" class="flex flex-col gap-3 border-b border-border bg-primary/5 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <Badge>{{ selectedOrganizations.length }}</Badge>
                        <div>
                            <p class="text-sm font-semibold text-foreground">{{ selectedOrganizations.length > 1 ? 'mutuelles sélectionnées' : 'mutuelle sélectionnée' }}</p>
                            <p class="text-xs text-muted-foreground">Archivage réversible et audité · {{ SELECTION_LIMIT }} au plus</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="can('mutual_organizations.export')" as="a" :href="exportUrl" size="sm" variant="outline"><Download class="h-4 w-4" />Exporter</Button>
                        <Button v-if="can('mutual_organizations.archive') && selectedActiveOrganizations.length" type="button" size="sm" variant="danger-outline" @click="openBulkAction('ORGANIZATIONS', 'ARCHIVE')"><Archive class="h-4 w-4" />Archiver ({{ selectedActiveOrganizations.length }})</Button>
                        <Button v-if="can('mutual_organizations.restore') && selectedArchivedOrganizations.length" type="button" size="sm" variant="outline" @click="openBulkAction('ORGANIZATIONS', 'RESTORE')"><RotateCcw class="h-4 w-4" />Restaurer ({{ selectedArchivedOrganizations.length }})</Button>
                        <Button type="button" size="sm" variant="ghost" @click="clearOrganizationSelection">Désélectionner</Button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead class="border-b border-border bg-muted/40">
                            <tr>
                                <th :class="cn(TH, 'w-10 ps-4')">
                                    <Checkbox :model-value="organizationsHeaderState" :disabled="! organizations.length" aria-label="Sélectionner toutes les mutuelles affichées" @update:model-value="toggleVisibleOrganizationSelection" />
                                </th>
                                <th :class="TH">Mutuelle</th>
                                <th :class="cn(TH, 'text-end')">Part mutuelle</th>
                                <th :class="cn(TH, 'text-end')">Part patient</th>
                                <th :class="cn(TH, 'text-end')">Couvertures en cours</th>
                                <th :class="cn(TH, 'text-end')">Dossiers associés</th>
                                <th :class="TH">État</th>
                                <th :class="cn(TH, 'pe-4 text-end')">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="organization in organizations"
                                :key="organization.uuid"
                                :class="cn('transition-colors hover:bg-muted/40', selectedOrganizationUuids.has(organization.uuid) && 'bg-primary/5')"
                            >
                                <td :class="cn(TD, 'ps-4')">
                                    <Checkbox :model-value="selectedOrganizationUuids.has(organization.uuid)" :aria-label="`Sélectionner ${organization.name}`" @update:model-value="toggleOrganizationSelection(organization.uuid)" />
                                </td>
                                <td :class="TD">
                                    <div class="flex items-center gap-3">
                                        <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', organization.active ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground')"><Building2 class="h-4 w-4" /></span>
                                        <div class="min-w-0">
                                            <p :class="cn('font-semibold', organization.active ? 'text-foreground' : 'text-muted-foreground line-through')">{{ organization.name }}</p>
                                            <p class="mt-0.5 truncate font-mono text-[10px] text-muted-foreground" :title="organization.uuid">{{ organization.uuid }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td :class="cn(TD, 'text-end')">
                                    <Badge variant="success" class="tabular-nums">{{ formatRate(organization.coverage_rate) }}</Badge>
                                </td>
                                <td :class="cn(TD, 'text-end font-semibold tabular-nums text-foreground')">{{ formatRate(organization.patient_rate) }}</td>
                                <td :class="cn(TD, 'text-end font-semibold tabular-nums', organization.active_coverages_count ? 'text-foreground' : 'text-muted-foreground')">{{ organization.active_coverages_count }}</td>
                                <td :class="cn(TD, 'text-end tabular-nums text-muted-foreground')">{{ organization.coverages_count }}</td>
                                <td :class="TD">
                                    <Badge v-if="organization.active" variant="success"><CircleCheck class="h-3 w-3" />Active</Badge>
                                    <Badge v-else variant="outline"><Archive class="h-3 w-3" />Archivée</Badge>
                                </td>
                                <td :class="cn(TD, 'pe-4 text-end')">
                                    <div class="inline-flex items-center gap-0.5">
                                        <Button v-if="organization.active && can('mutual_organizations.update')" type="button" variant="ghost" icon size="sm" :title="`Modifier ${organization.name}`" :aria-label="`Modifier ${organization.name}`" @click="openEditOrganization(organization)"><Pencil class="h-4 w-4" /></Button>
                                        <Button v-if="organization.active && can('mutual_organizations.archive')" type="button" variant="ghost" icon size="sm" class="hover:text-destructive" :title="`Archiver ${organization.name}`" :aria-label="`Archiver ${organization.name}`" @click="openArchiveOrganization(organization)"><Archive class="h-4 w-4" /></Button>
                                        <Button v-if="! organization.active && can('mutual_organizations.restore')" type="button" variant="outline" size="xs" @click="restoreOrganization(organization)"><RotateCcw class="h-3.5 w-3.5" />Restaurer</Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="! organizations.length">
                                <td colspan="8" class="px-5 py-14 text-center">
                                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground"><Building2 class="h-6 w-6" /></span>
                                    <p class="mt-3 text-sm font-semibold text-foreground">Aucune mutuelle ne correspond</p>
                                    <p class="mt-1 text-xs text-muted-foreground">Changez de carte ou de recherche.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-xs text-muted-foreground">
                    <span>{{ plural(organizations.length, 'mutuelle affichée', 'mutuelles affichées') }}</span>
                    <span>Archivage réversible et audité</span>
                </div>
            </Card>
        </template>

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
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><FileSpreadsheet class="h-5 w-5" /></span>
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
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', bulkAction?.mode === 'ARCHIVE' ? 'bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300')">
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
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="editingOrganization ? Pencil : Building2" class="h-5 w-5" /></span>
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
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300"><Archive class="h-5 w-5" /></span>
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

        <!-- Créer / modifier une désignation -->
        <Dialog
            :open="itemModalOpen"
            size="xl"
            :title="editingItem ? `Modifier « ${editingItem.name} »` : 'Nouvelle désignation'"
            :description="`Site destinataire : ${selectedSite?.site.name ?? '—'}. Le code et le type ne changent plus après la création.`"
            :dismissible="false"
            @update:open="(value) => value || closeItemModal()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><component :is="editingItem ? Pencil : ListChecks" class="h-5 w-5" /></span>
            </template>
            <form id="tariffs-item-form" class="space-y-5" @submit.prevent="submitItem">
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Code" required :error="itemForm.errors.code">
                        <IconInput v-model="itemForm.code" :icon="Hash" :disabled="Boolean(editingItem)" class="uppercase" placeholder="ECHO-ABD" />
                    </FormField>
                    <FormField label="Désignation" required :error="itemForm.errors.name" class="lg:col-span-2">
                        <Input v-model="itemForm.name" placeholder="Nom clair de la prestation" />
                    </FormField>
                    <FormField label="Unité" required :error="itemForm.errors.unit">
                        <Input v-model="itemForm.unit" placeholder="acte" />
                    </FormField>
                    <FormField label="Type" required as="div" :error="itemForm.errors.type">
                        <Select v-model="itemForm.type" :options="options.types" :disabled="Boolean(editingItem)" class="w-full" aria-label="Type" />
                    </FormField>
                    <FormField label="Module" required as="div" :error="itemForm.errors.module">
                        <Select v-model="itemForm.module" :options="options.modules" class="w-full" aria-label="Module" />
                    </FormField>
                    <FormField
                        v-if="itemForm.module === IMAGING"
                        label="Famille d’imagerie"
                        as="div"
                        :error="itemForm.errors.imaging_modality"
                    >
                        <Select v-model="itemForm.imaging_modality" :options="imagingModalityOptions" :icon="ScanLine" class="w-full" aria-label="Famille d’imagerie" />
                    </FormField>
                    <FormField label="Description" hint="(facultatif)" :error="itemForm.errors.description" class="md:col-span-2">
                        <Input v-model="itemForm.description" placeholder="Précision facultative" />
                    </FormField>
                </div>

                <section v-if="itemForm.type === 'SERVICE'" class="rounded-xl border border-border p-4">
                    <label class="flex cursor-pointer items-start justify-between gap-4">
                        <span>
                            <span class="block text-sm font-semibold text-foreground">Disponible à la Réception</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">La réceptionniste pourra choisir cette prestation lors de la création du passage.</span>
                        </span>
                        <Switch v-model="itemForm.reception_selectable" aria-label="Disponible à la Réception" />
                    </label>
                    <FormField v-if="itemForm.reception_selectable" label="Parcours clinique" required as="div" :error="itemForm.errors.reception_routing_mode" class="mt-4 max-w-xl">
                        <Select v-model="routingModeModel" :options="routingModeOptions" class="w-full" aria-label="Parcours clinique" />
                    </FormField>
                </section>

                <section v-if="isCareService" class="grid gap-3 md:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border p-4">
                        <Checkbox v-model="itemForm.care_requires_allergy_check" class="mt-0.5" />
                        <span>
                            <span class="block text-sm font-semibold text-foreground">Vérifier les allergies</span>
                            <span class="block text-xs text-muted-foreground">Recommandation affichée dans la fiche Soins.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border p-4">
                        <Checkbox v-model="itemForm.care_recommends_vitals" class="mt-0.5" />
                        <span>
                            <span class="block text-sm font-semibold text-foreground">Relever les constantes</span>
                            <span class="block text-xs text-muted-foreground">Recommandation affichée à l’infirmier.</span>
                        </span>
                    </label>
                </section>

                <section v-if="itemForm.billable" class="rounded-xl border border-border p-4">
                    <FormField label="Politique Avantage Personnel" as="div" :error="itemForm.errors.staff_coverage_policy" class="max-w-xl">
                        <Select v-model="itemForm.staff_coverage_policy" :options="options.staff_coverage_policies ?? []" class="w-full" aria-label="Politique Avantage Personnel" />
                    </FormField>
                    <p class="mt-1.5 text-xs text-muted-foreground">Le crédit Bloc dépend uniquement de cette classification explicite, jamais du nom, du code ou du module.</p>
                </section>

                <section v-if="! editingItem && selectedType?.billable" class="rounded-xl border border-border p-4">
                    <h3 class="text-sm font-semibold text-foreground">Tarifs initiaux</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">Le tarif standard est obligatoire. Le tarif mutuelle reste distinct et ne reprend jamais le standard.</p>
                    <div class="mt-3 grid gap-4 md:grid-cols-3">
                        <FormField label="Sans mutuelle (Ar)" required :error="itemForm.errors.tariff_amount">
                            <Input v-model="itemForm.tariff_amount" type="number" min="1" step="1" />
                        </FormField>
                        <FormField label="Mutuelle (Ar)" hint="(facultatif)" :error="itemForm.errors.mutual_tariff_amount">
                            <Input v-model="itemForm.mutual_tariff_amount" type="number" min="1" step="1" />
                        </FormField>
                        <FormField label="Motif" required :error="itemForm.errors.tariff_reason">
                            <Input v-model="itemForm.tariff_reason" placeholder="Tarif initial validé" />
                        </FormField>
                    </div>
                </section>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="itemForm.processing" @click="closeItemModal">Annuler</Button>
                <Button type="submit" form="tariffs-item-form" :disabled="itemForm.processing">
                    <CircleCheck class="h-4 w-4" />{{ itemForm.processing ? 'Enregistrement…' : editingItem ? 'Enregistrer' : 'Créer sur le site' }}
                </Button>
            </template>
        </Dialog>

        <!-- Tarifs et historique d'une désignation -->
        <Dialog
            :open="tariffTarget !== null"
            size="xl"
            :title="tariffTarget ? `Tarifs · ${tariffTarget.name}` : ''"
            :description="tariffTarget ? `${tariffTarget.code} · ${selectedSite?.site.name ?? '—'} · chaque modification crée une nouvelle version.` : ''"
            :dismissible="false"
            @update:open="(value) => value || closeTariff()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><History class="h-5 w-5" /></span>
            </template>
            <div v-if="tariffTarget" class="space-y-4">
                <Tabs :model-value="tariffForm.tariff_category" @update:model-value="chooseTariffCategory">
                    <TabsList aria-label="Grille tarifaire">
                        <TabsTrigger v-for="category in options.tariff_categories" :key="category.value" :value="category.value">{{ category.label }}</TabsTrigger>
                    </TabsList>
                </Tabs>

                <form id="tariffs-tariff-form" class="grid gap-4 rounded-xl border border-border p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]" @submit.prevent="submitTariff">
                    <FormField label="Nouveau tarif (Ar)" required :error="tariffForm.errors.tariff_amount">
                        <Input v-model="tariffForm.tariff_amount" type="number" min="1" step="1" />
                    </FormField>
                    <FormField label="Motif du changement" required :error="tariffForm.errors.reason">
                        <Input v-model="tariffForm.reason" placeholder="Décision tarifaire validée" />
                    </FormField>
                </form>

                <div class="overflow-hidden rounded-xl border border-border">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/40 px-4 py-3">
                        <div>
                            <h3 class="text-sm font-semibold text-foreground">Historique {{ tariffForm.tariff_category === 'MUTUAL' ? 'Mutuelle' : 'Sans mutuelle' }}</h3>
                            <p class="mt-0.5 text-xs text-muted-foreground">Les montants précédents restent intacts pour les anciennes factures.</p>
                        </div>
                        <Button v-if="currentTariff && can('catalog.tariffs.archive')" type="button" variant="danger-outline" size="sm" @click="requestArchiveTariff"><Ban class="h-4 w-4" />Suspendre le tarif actif</Button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="border-b border-border">
                                <tr>
                                    <th :class="cn(TH, 'ps-4')">Montant</th>
                                    <th :class="TH">Période</th>
                                    <th :class="TH">Motif</th>
                                    <th :class="cn(TH, 'pe-4')">État</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="tariff in tariffHistory" :key="tariff.uuid">
                                    <td :class="cn(TD, 'ps-4 font-semibold tabular-nums text-foreground')">{{ formatMoney(tariff.amount, tariff.currency) }}</td>
                                    <td :class="cn(TD, 'text-xs text-muted-foreground')">{{ formatDateTime(tariff.effective_from) }}<span v-if="tariff.effective_until"> → {{ formatDateTime(tariff.effective_until) }}</span></td>
                                    <td :class="cn(TD, 'text-xs text-muted-foreground')">{{ tariff.change_reason }}</td>
                                    <td :class="cn(TD, 'pe-4')">
                                        <Badge v-if="tariff.current" variant="success"><CircleCheck class="h-3 w-3" />Actif</Badge>
                                        <Badge v-else variant="outline">Historique</Badge>
                                    </td>
                                </tr>
                                <tr v-if="! tariffHistory.length">
                                    <td colspan="4" class="px-4 py-8 text-center text-xs text-muted-foreground">Aucun tarif enregistré dans cette grille.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button type="button" variant="outline" :disabled="tariffForm.processing" @click="closeTariff">Fermer</Button>
                <Button type="submit" form="tariffs-tariff-form" :disabled="tariffForm.processing">
                    <CircleCheck class="h-4 w-4" />{{ tariffForm.processing ? 'Enregistrement…' : 'Enregistrer le tarif' }}
                </Button>
            </template>
        </Dialog>

        <!-- Archiver une désignation / suspendre un tarif -->
        <Dialog
            :open="confirmation !== null"
            :title="confirmation?.mode === 'tariff' ? 'Suspendre ce tarif ?' : 'Archiver cette désignation ?'"
            :description="confirmation?.mode === 'tariff'
                ? 'Aucun tarif de remplacement n’est inventé : cette grille reste non configurée jusqu’à une nouvelle décision.'
                : 'Elle ne sera plus proposée pour les nouveaux passages. Les factures et passages existants restent inchangés.'"
            :dismissible="false"
            @update:open="(value) => value || closeConfirmation()"
        >
            <template #icon>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600 dark:bg-red-950/40 dark:text-red-300"><component :is="confirmation?.mode === 'tariff' ? Ban : Archive" class="h-5 w-5" /></span>
            </template>
            <form id="tariffs-confirmation-form" @submit.prevent="submitConfirmation">
                <p v-if="confirmation?.item" class="mb-3 text-sm text-foreground"><strong>{{ confirmation.item.name }}</strong> <span class="font-mono text-xs text-muted-foreground">{{ confirmation.item.code }}</span></p>
                <FormField label="Motif" required :error="confirmationForm.errors.reason">
                    <Textarea v-model="confirmationForm.reason" :rows="3" placeholder="Précisez la décision" />
                </FormField>
            </form>
            <template #footer>
                <Button type="button" variant="outline" :disabled="confirmationForm.processing" @click="closeConfirmation">Annuler</Button>
                <Button type="submit" form="tariffs-confirmation-form" variant="danger" :disabled="confirmationForm.processing || ! confirmationForm.reason.trim()">
                    <component :is="confirmation?.mode === 'tariff' ? Ban : Archive" class="h-4 w-4" />Confirmer
                </Button>
            </template>
        </Dialog>
    </div>
</template>
