<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import FormError from '@/Components/UI/FormError.vue';
import Icon from '@/Components/UI/Icon.vue';
import Input from '@/Components/UI/Input.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/utilities/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    items: Object,
    filters: Object,
    types: Array,
    modules: Array,
    receptionRoutingModes: Array,
    tariffCategories: Array,
    staffCoveragePolicies: Array,
    summary: Object,
    pendingMedicines: { type: Array, default: () => [] },
});

const page = usePage();
const { can } = usePermissions();
const query = ref(props.filters.q ?? '');
const typeFilter = ref(props.filters.type ?? '');
const moduleFilter = ref(props.filters.module ?? '');
const statusFilter = ref(props.filters.status ?? 'active');
const formOpen = ref(false);
const editingItem = ref(null);
const tariffTarget = ref(null);
const archiveTarget = ref(null);
const archiveMode = ref('item');
const archiveTariffCategory = ref('STANDARD');

const itemForm = useForm({
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
    clinician_orderable: false,
    description: '',
    tariff_amount: '',
    mutual_tariff_amount: '',
    tariff_reason: '',
});
const tariffForm = useForm({ tariff_category: 'STANDARD', tariff_amount: '', reason: '' });
const archiveForm = useForm({ reason: '' });

const isEditing = computed(() => editingItem.value !== null);
const selectedType = computed(() => props.types.find((type) => type.value === itemForm.type));
const canSeeTariffs = computed(() => can('catalog.tariffs.view'));
const selectedCurrentTariff = computed(() => {
    if (!tariffTarget.value) return null;

    return tariffForm.tariff_category === 'MUTUAL'
        ? tariffTarget.value.current_mutual_tariff
        : tariffTarget.value.current_standard_tariff;
});
const selectedTariffHistory = computed(() => tariffTarget.value?.tariffs
    ?.filter((tariff) => tariff.tariff_category === tariffForm.tariff_category) ?? []);
const archiveTariffLabel = computed(() => props.tariffCategories
    .find((category) => category.value === archiveTariffCategory.value)?.label ?? 'sélectionné');

watch(() => itemForm.type, (value) => {
    if (isEditing.value) return;

    if (value === 'SERVICE') {
        itemForm.billable = true;
        itemForm.stockable = false;
        itemForm.unit = itemForm.unit || 'acte';
    } else if (value === 'MEDICINE' || value === 'CONSUMABLE') {
        itemForm.reception_selectable = false;
        itemForm.reception_routing_mode = null;
        itemForm.billable = true;
        itemForm.stockable = true;
        itemForm.unit = itemForm.unit === 'acte' ? 'unité' : itemForm.unit;
    } else {
        itemForm.reception_selectable = false;
        itemForm.reception_routing_mode = null;
        itemForm.billable = false;
        itemForm.stockable = false;
        itemForm.unit = itemForm.unit === 'acte' ? 'unité' : itemForm.unit;
    }
});

watch(() => [itemForm.type, itemForm.module], ([type, module]) => {
    if (type === 'SERVICE' && module === 'CARE') return;

    itemForm.care_requires_allergy_check = false;
    itemForm.care_recommends_vitals = false;
    itemForm.clinician_orderable = false;
});

watch(() => itemForm.billable, (billable) => {
    if (!billable) itemForm.staff_coverage_policy = 'UNCLASSIFIED';
});

const submitFilters = () => {
    router.get('/administration/catalog', {
        q: query.value || undefined,
        type: typeFilter.value || undefined,
        module: moduleFilter.value || undefined,
        status: statusFilter.value,
    }, { preserveState: true, replace: true });
};

const openCreate = () => {
    editingItem.value = null;
    itemForm.reset();
    itemForm.clearErrors();
    itemForm.type = 'SERVICE';
    itemForm.module = 'RECEPTION';
    itemForm.unit = 'acte';
    itemForm.billable = true;
    itemForm.stockable = false;
    itemForm.reception_selectable = false;
    itemForm.reception_routing_mode = null;
    itemForm.staff_coverage_policy = 'UNCLASSIFIED';
    itemForm.care_requires_allergy_check = false;
    itemForm.care_recommends_vitals = false;
    itemForm.clinician_orderable = false;
    formOpen.value = true;
};

const openEdit = (item) => {
    editingItem.value = item;
    itemForm.clearErrors();
    itemForm.code = item.code;
    itemForm.name = item.name;
    itemForm.type = item.type;
    itemForm.module = item.module;
    itemForm.unit = item.unit;
    itemForm.billable = item.billable;
    itemForm.stockable = item.stockable;
    itemForm.reception_selectable = item.reception_selectable;
    itemForm.reception_routing_mode = item.reception_routing_mode;
    itemForm.staff_coverage_policy = item.staff_coverage_policy;
    itemForm.care_requires_allergy_check = item.care_requires_allergy_check;
    itemForm.care_recommends_vitals = item.care_recommends_vitals;
    itemForm.clinician_orderable = item.clinician_orderable;
    itemForm.description = item.description ?? '';
    itemForm.tariff_amount = '';
    itemForm.mutual_tariff_amount = '';
    itemForm.tariff_reason = '';
    formOpen.value = true;
};

const closeItemForm = () => {
    if (itemForm.processing) return;
    formOpen.value = false;
    editingItem.value = null;
    itemForm.reset();
    itemForm.clearErrors();
};

const submitItem = () => {
    if (isEditing.value) {
        itemForm.transform((data) => ({
            name: data.name,
            module: data.module,
            unit: data.unit,
            description: data.description || null,
            reception_selectable: data.reception_selectable,
            reception_routing_mode: data.reception_selectable ? data.reception_routing_mode : null,
            staff_coverage_policy: data.staff_coverage_policy,
            care_requires_allergy_check: data.type === 'SERVICE' && data.module === 'CARE'
                ? data.care_requires_allergy_check
                : false,
            care_recommends_vitals: data.type === 'SERVICE' && data.module === 'CARE'
                ? data.care_recommends_vitals
                : false,
            clinician_orderable: data.type === 'SERVICE' && data.module === 'CARE'
                ? data.clinician_orderable
                : false,
        })).put(`/administration/catalog/${editingItem.value.uuid}`, {
            preserveScroll: true,
            onSuccess: closeItemForm,
        });
        return;
    }

    itemForm.transform((data) => ({
        ...data,
        care_requires_allergy_check: data.type === 'SERVICE' && data.module === 'CARE'
            ? data.care_requires_allergy_check
            : false,
        care_recommends_vitals: data.type === 'SERVICE' && data.module === 'CARE'
            ? data.care_recommends_vitals
            : false,
        clinician_orderable: data.type === 'SERVICE' && data.module === 'CARE'
            ? data.clinician_orderable
            : false,
    })).post('/administration/catalog', {
        preserveScroll: true,
        onSuccess: closeItemForm,
    });
};

const chooseTariffCategory = (category) => {
    tariffForm.tariff_category = category;
    tariffForm.tariff_amount = category === 'MUTUAL'
        ? (tariffTarget.value?.current_mutual_tariff?.amount ?? '')
        : (tariffTarget.value?.current_standard_tariff?.amount ?? '');
    tariffForm.clearErrors();
};

const openTariff = (item, category = 'STANDARD') => {
    tariffTarget.value = item;
    tariffForm.reset();
    tariffForm.clearErrors();
    chooseTariffCategory(category);
};

const closeTariff = () => {
    if (tariffForm.processing) return;
    tariffTarget.value = null;
    tariffForm.reset();
    tariffForm.clearErrors();
};

const submitTariff = () => {
    tariffForm.post(`/administration/catalog/${tariffTarget.value.uuid}/tariff`, {
        preserveScroll: true,
        onSuccess: closeTariff,
    });
};

const openArchive = (item, mode = 'item', tariffCategory = 'STANDARD') => {
    if (mode === 'tariff') tariffTarget.value = null;
    archiveTarget.value = item;
    archiveMode.value = mode;
    archiveTariffCategory.value = tariffCategory;
    archiveForm.reset();
    archiveForm.clearErrors();
};

const closeArchive = () => {
    if (archiveForm.processing) return;
    archiveTarget.value = null;
    archiveForm.reset();
    archiveForm.clearErrors();
};

const submitArchive = () => {
    const options = { preserveScroll: true, onSuccess: closeArchive };

    if (archiveMode.value === 'tariff') {
        archiveForm.transform((data) => ({
            ...data,
            tariff_category: archiveTariffCategory.value,
        })).post(`/administration/catalog/${archiveTarget.value.uuid}/tariff/archive`, options);
        return;
    }

    archiveForm.delete(`/administration/catalog/${archiveTarget.value.uuid}`, options);
};

const restoreItem = (item) => {
    router.post(`/administration/catalog/${item.uuid}/restore`, {}, { preserveScroll: true });
};

const reviewTarget = ref(null);
const reviewForm = useForm({ note: '' });
const openReview = (line) => {
    reviewTarget.value = line;
    reviewForm.reset();
    reviewForm.clearErrors();
};
const closeReview = () => {
    if (reviewForm.processing) return;
    reviewTarget.value = null;
    reviewForm.reset();
    reviewForm.clearErrors();
};
const submitReview = () => {
    reviewForm.post(`/administration/catalog/pending-medicines/${reviewTarget.value.id}/review`, {
        preserveScroll: true,
        onSuccess: closeReview,
    });
};

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Référentiels et tarifs" />

    <div class="w-full space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-400">
                    <span>Super Administration</span>
                    <Icon class="text-sm" name="chevron-right" />
                    <span>Configuration locale</span>
                </div>
                <h1 class="mt-1 font-heading text-2xl font-bold text-slate-700 dark:text-white">Référentiels & tarifs</h1>
                <p class="mt-1 text-sm text-slate-500">Produits et prestations du site {{ page.props.site?.name }}. Les anciens tarifs restent historisés.</p>
            </div>

            <Button v-if="can('catalog.items.create')" size="rg" variant="primary" type="button" @click="openCreate">
                <Icon class="text-lg" name="plus" />
                <span class="ms-2">Nouvel élément</span>
            </Button>
        </div>

        <section class="grid overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 sm:grid-cols-2 xl:grid-cols-5">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e xl:border-b-0">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Éléments actifs</p>
                <p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.active }}</p>
            </div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 xl:border-b-0 xl:border-e">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Facturables</p>
                <p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.billable }}</p>
            </div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e xl:border-b-0">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Sans tarif standard</p>
                <p class="mt-1 flex items-center gap-2 text-xl font-bold text-slate-700 dark:text-white">
                    {{ summary.without_standard_tariff ?? '—' }}
                    <span v-if="summary.without_standard_tariff" class="h-2 w-2 rounded-full bg-amber-500" aria-label="Attention requise"></span>
                </p>
            </div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e sm:border-b-0 xl:border-b-0">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Sans tarif mutuelle</p>
                <p class="mt-1 flex items-center gap-2 text-xl font-bold text-slate-700 dark:text-white">
                    {{ summary.without_mutual_tariff ?? '—' }}
                    <span v-if="summary.without_mutual_tariff" class="h-2 w-2 rounded-full bg-amber-500" aria-label="Attention requise"></span>
                </p>
            </div>
            <div class="px-5 py-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Archivés</p>
                <p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.archived }}</p>
            </div>
        </section>

        <section v-if="pendingMedicines.length" class="overflow-hidden rounded-lg border border-amber-200 bg-amber-50/40 dark:border-amber-900 dark:bg-amber-950/10">
            <div class="flex items-center gap-3 border-b border-amber-200 px-5 py-4 dark:border-amber-900">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"><Icon class="text-lg" name="alert-circle" /></span>
                <div>
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Médicaments demandés par les médecins</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Ajoutés manuellement sur une ordonnance faute d’être au référentiel Pharmacie. Aucun stock ni prix n’a été engagé ; le médecin n’a jamais eu accès à un tarif.</p>
                </div>
            </div>
            <ul class="divide-y divide-amber-200/70 dark:divide-amber-900/60">
                <li v-for="line in pendingMedicines" :key="line.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-slate-700 dark:text-white">{{ line.medication_name }}<span class="ms-2 text-xs font-normal text-slate-400">× {{ line.quantity }}</span></p>
                        <p v-if="line.dosage || line.frequency || line.duration" class="mt-1 text-xs text-slate-500">{{ [line.dosage, line.frequency, line.duration].filter(Boolean).join(' · ') }}</p>
                        <p v-if="line.instructions" class="mt-1 text-xs text-slate-400">{{ line.instructions }}</p>
                        <p class="mt-2 text-[11px] text-slate-400">Prescrit par {{ line.prescribed_by || 'N/R' }} · Passage {{ line.episode_number || 'N/R' }} · Patient {{ line.patient_number || 'N/R' }} · {{ formatDateTime(line.prescribed_at) }}</p>
                    </div>
                    <Button v-if="can('catalog.items.create')" type="button" size="sm" variant="white-outline" class="shrink-0" @click="openReview(line)"><Icon class="me-1.5 text-base" name="check" />Marquer traité</Button>
                </li>
            </ul>
        </section>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-900 xl:flex-row xl:items-center xl:justify-between xl:px-5">
                <form class="relative w-full xl:max-w-md" role="search" @submit.prevent="submitFilters">
                    <Input v-model="query" icon="start" type="search" placeholder="Code ou désignation" autocomplete="off" />
                    <button type="submit" class="absolute inset-y-0 start-0 flex w-9 items-center justify-center text-slate-400" aria-label="Rechercher"><Icon class="text-lg" name="search" /></button>
                </form>

                <div class="grid gap-2 sm:grid-cols-3">
                    <label class="sr-only" for="catalog-type-filter">Filtrer par type</label>
                    <select id="catalog-type-filter" v-model="typeFilter" class="h-9 min-w-40 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200" @change="submitFilters">
                        <option value="">Tous les types</option>
                        <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                    <label class="sr-only" for="catalog-module-filter">Filtrer par module</label>
                    <select id="catalog-module-filter" v-model="moduleFilter" class="h-9 min-w-40 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200" @change="submitFilters">
                        <option value="">Tous les modules</option>
                        <option v-for="module in modules" :key="module.value" :value="module.value">{{ module.label }}</option>
                    </select>
                    <label class="sr-only" for="catalog-status-filter">Filtrer par état</label>
                    <select id="catalog-status-filter" v-model="statusFilter" class="h-9 min-w-32 rounded border-gray-200 bg-white py-1.5 ps-3 pe-8 text-sm text-slate-600 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200" @change="submitFilters">
                        <option value="active">Actifs</option>
                        <option value="archived">Archivés</option>
                        <option value="all">Tous</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] border-collapse">
                    <caption class="sr-only">Référentiel des produits et prestations du site</caption>
                    <thead>
                        <tr class="bg-gray-50/70 dark:bg-gray-1000/40">
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Code / désignation</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Type</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Module</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Sans mutuelle</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Mutuelle</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-start text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">État</th>
                            <th class="border-b border-gray-200 px-5 py-2.5 text-end text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items.data" :key="item.uuid" class="transition-colors hover:bg-gray-50/70 dark:hover:bg-gray-1000">
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <div class="min-w-[270px]">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-slate-700 dark:text-white">{{ item.name }}</span>
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-gray-900">{{ item.code }}</span>
                                    </div>
                                    <p class="mt-1 line-clamp-1 text-xs text-slate-400">{{ item.description || `Unité : ${item.unit}` }}</p>
                                    <p v-if="item.reception_selectable" class="mt-1 text-[11px] font-semibold text-primary-600">Réception · {{ item.reception_routing_label }}</p>
                                </div>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <p class="text-sm font-medium text-slate-600 dark:text-slate-200">{{ item.type_label }}</p>
                                <p class="mt-0.5 text-xs text-slate-400">{{ item.stockable ? 'Stockable' : 'Sans stock quantitatif' }}</p>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-sm text-slate-500 dark:border-gray-900">{{ item.module_label }}</td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <template v-if="canSeeTariffs">
                                    <p v-if="item.current_standard_tariff" class="text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(item.current_standard_tariff.amount, item.current_standard_tariff.currency) }}</p>
                                    <p v-else-if="item.billable" class="text-xs font-medium text-amber-700 dark:text-amber-300">Tarif suspendu</p>
                                    <p v-else class="text-xs text-slate-400">Non facturable</p>
                                </template>
                                <span v-else class="text-xs text-slate-400">Accès restreint</span>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <template v-if="canSeeTariffs">
                                    <p v-if="item.current_mutual_tariff" class="text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(item.current_mutual_tariff.amount, item.current_mutual_tariff.currency) }}</p>
                                    <button v-else-if="item.billable && !item.archived && (can('catalog.tariffs.create') || can('catalog.tariffs.update'))" type="button" class="text-xs font-semibold text-primary-600 hover:text-primary-700" @click="openTariff(item, 'MUTUAL')">À configurer</button>
                                    <p v-else-if="item.billable" class="text-xs font-medium text-amber-700 dark:text-amber-300">Non configuré</p>
                                    <p v-else class="text-xs text-slate-400">Non facturable</p>
                                </template>
                                <span v-else class="text-xs text-slate-400">Accès restreint</span>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 dark:border-gray-900">
                                <span v-if="item.archived" class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span> Archivé</span>
                                <span v-else class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Actif</span>
                            </td>
                            <td class="border-b border-gray-200 px-5 py-3 text-end dark:border-gray-900">
                                <div class="inline-flex items-center gap-1">
                                    <button v-if="!item.archived && can('catalog.items.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" title="Modifier" :aria-label="`Modifier ${item.name}`" @click="openEdit(item)"><Icon class="text-base" name="edit" /></button>
                                    <button v-if="!item.archived && item.billable && canSeeTariffs && (can('catalog.tariffs.create') || can('catalog.tariffs.update'))" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" title="Tarif et historique" :aria-label="`Gérer le tarif de ${item.name}`" @click="openTariff(item)"><Icon class="text-base" name="history" /></button>
                                    <button v-if="!item.archived && can('catalog.items.delete')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-red-300 hover:text-red-600 dark:border-gray-800" title="Archiver" :aria-label="`Archiver ${item.name}`" @click="openArchive(item)"><Icon class="text-base" name="archive" /></button>
                                    <button v-if="item.archived && can('catalog.items.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" title="Restaurer" :aria-label="`Restaurer ${item.name}`" @click="restoreItem(item)"><Icon class="text-base" name="reload" /></button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="items.data.length === 0">
                            <td colspan="7" class="px-5 py-12 text-center">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="file-text" /></span>
                                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-200">Aucun élément trouvé</p>
                                <p class="mt-1 text-xs text-slate-400">Modifiez les filtres ou créez la première désignation.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="items.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 p-4 dark:border-gray-900">
                <span class="text-xs text-slate-400">Page {{ items.current_page }} sur {{ items.last_page }}</span>
                <div class="flex flex-wrap items-center gap-1">
                    <template v-for="(link, index) in items.links" :key="index">
                        <Link v-if="link.url" :href="link.url" preserve-state :class="['rounded px-3 py-1.5 text-sm', link.active ? 'bg-primary-600 text-white' : 'text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900']" v-html="link.label" />
                        <span v-else class="rounded px-3 py-1.5 text-sm text-slate-300" v-html="link.label" />
                    </template>
                </div>
            </div>
        </section>

        <aside class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-900 dark:bg-gray-950 sm:px-5">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900"><Icon class="text-lg" name="shield-check" /></span>
                <div>
                    <h2 class="text-sm font-bold text-slate-700 dark:text-white">Prix maîtrisés côté serveur</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">La caisse sélectionne une prestation : elle ne saisit plus son prix. Chaque changement crée une nouvelle version datée et auditée, sans modifier les factures existantes.</p>
                </div>
            </div>
        </aside>

        <div v-if="formOpen" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeItemForm">
            <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="catalog-form-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div><h2 id="catalog-form-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ isEditing ? 'Modifier la désignation' : 'Nouvel élément du référentiel' }}</h2><p class="mt-1 text-xs text-slate-500">Le code et le type deviennent immuables après création afin de préserver l’historique.</p></div>
                    <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white" aria-label="Fermer" @click="closeItemForm"><Icon class="text-xl" name="cross" /></button>
                </header>

                <form class="overflow-y-auto" @submit.prevent="submitItem">
                    <div class="space-y-5 p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="catalog_code" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Code <span class="text-red-500">*</span></label>
                                <Input id="catalog_code" v-model="itemForm.code" :disabled="isEditing" placeholder="EX. CONSULT-GEN" :aria-invalid="Boolean(itemForm.errors.code)" />
                                <FormError v-if="itemForm.errors.code">{{ itemForm.errors.code }}</FormError>
                            </div>
                            <div>
                                <label for="catalog_name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Désignation <span class="text-red-500">*</span></label>
                                <Input id="catalog_name" v-model="itemForm.name" placeholder="Consultation générale" :aria-invalid="Boolean(itemForm.errors.name)" />
                                <FormError v-if="itemForm.errors.name">{{ itemForm.errors.name }}</FormError>
                            </div>
                            <div>
                                <label for="catalog_type" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type <span class="text-red-500">*</span></label>
                                <select id="catalog_type" v-model="itemForm.type" :disabled="isEditing" class="block h-9 w-full rounded border-gray-200 bg-white py-1.5 ps-3 pe-9 text-sm text-slate-700 focus:border-primary-500 focus:ring-primary-200 disabled:bg-gray-50 disabled:text-slate-400 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-900">
                                    <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                                </select>
                                <FormError v-if="itemForm.errors.type">{{ itemForm.errors.type }}</FormError>
                            </div>
                            <div>
                                <label for="catalog_module" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Module responsable <span class="text-red-500">*</span></label>
                                <select id="catalog_module" v-model="itemForm.module" class="block h-9 w-full rounded border-gray-200 bg-white py-1.5 ps-3 pe-9 text-sm text-slate-700 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                                    <option v-for="module in modules" :key="module.value" :value="module.value">{{ module.label }}</option>
                                </select>
                                <FormError v-if="itemForm.errors.module">{{ itemForm.errors.module }}</FormError>
                            </div>
                            <div>
                                <label for="catalog_unit" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Unité <span class="text-red-500">*</span></label>
                                <Input id="catalog_unit" v-model="itemForm.unit" placeholder="acte, boîte, unité…" :aria-invalid="Boolean(itemForm.errors.unit)" />
                                <FormError v-if="itemForm.errors.unit">{{ itemForm.errors.unit }}</FormError>
                            </div>
                            <div v-if="!isEditing" class="rounded border border-gray-200 p-3 dark:border-gray-800">
                                <p class="text-xs font-bold text-slate-600 dark:text-slate-200">Comportement</p>
                                <div class="mt-2 flex flex-wrap gap-5">
                                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input v-model="itemForm.billable" type="checkbox" :disabled="itemForm.type === 'SERVICE'" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" /> Facturable</label>
                                    <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input v-model="itemForm.stockable" type="checkbox" :disabled="!selectedType?.stockable" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" /> Stockable</label>
                                </div>
                                <FormError v-if="itemForm.errors.billable">{{ itemForm.errors.billable }}</FormError>
                                <FormError v-if="itemForm.errors.stockable">{{ itemForm.errors.stockable }}</FormError>
                            </div>
                            <div v-if="itemForm.type === 'SERVICE'" class="rounded border border-gray-200 p-3 dark:border-gray-800 sm:col-span-2">
                                <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200"><input v-model="itemForm.reception_selectable" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" /> Disponible à la Réception</label>
                                <p class="mt-1 text-xs text-slate-400">Autorise la sélection lors d’un passage et impose un parcours clinique côté serveur.</p>
                                <div v-if="itemForm.reception_selectable" class="mt-3 max-w-md">
                                    <label for="catalog_reception_route" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300">Parcours du patient <span class="text-red-500">*</span></label>
                                    <select id="catalog_reception_route" v-model="itemForm.reception_routing_mode" class="block h-9 w-full rounded border-gray-200 bg-white py-1.5 ps-3 pe-9 text-sm text-slate-700 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                                        <option :value="null">Choisir le parcours</option>
                                        <option v-for="mode in receptionRoutingModes" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                                    </select>
                                    <FormError v-if="itemForm.errors.reception_routing_mode">{{ itemForm.errors.reception_routing_mode }}</FormError>
                                </div>
                                <FormError v-if="itemForm.errors.reception_selectable">{{ itemForm.errors.reception_selectable }}</FormError>
                            </div>
                            <div v-if="itemForm.type === 'SERVICE' && itemForm.module === 'CARE'" class="rounded border border-gray-200 p-3 dark:border-gray-800 sm:col-span-2">
                                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Exigences pendant le soin</p>
                                <p class="mt-1 text-xs text-slate-400">Ces règles pilotent la fiche infirmière sans rendre toutes les constantes obligatoires.</p>
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label class="flex items-start gap-2 rounded border border-gray-200 p-3 text-sm text-slate-600 dark:border-gray-800 dark:text-slate-300">
                                        <input v-model="itemForm.care_requires_allergy_check" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                        <span><span class="block font-semibold text-slate-700 dark:text-white">Vérifier les allergies</span><span class="mt-0.5 block text-xs text-slate-400">Confirmation exigée avant une injection, perfusion ou autre acte configuré à risque.</span></span>
                                    </label>
                                    <label class="flex items-start gap-2 rounded border border-gray-200 p-3 text-sm text-slate-600 dark:border-gray-800 dark:text-slate-300">
                                        <input v-model="itemForm.care_recommends_vitals" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                        <span><span class="block font-semibold text-slate-700 dark:text-white">Recommander les constantes</span><span class="mt-0.5 block text-xs text-slate-400">Ouvre la section clinique par défaut, sans inventer de valeur obligatoire.</span></span>
                                    </label>
                                    <label class="flex items-start gap-2 rounded border border-gray-200 p-3 text-sm text-slate-600 dark:border-gray-800 dark:text-slate-300">
                                        <input v-model="itemForm.clinician_orderable" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                        <span><span class="block font-semibold text-slate-700 dark:text-white">Prescriptible par un médecin</span><span class="mt-0.5 block text-xs text-slate-400">Rend cet acte sélectionnable dans un ordre de soins Médecine → Soins.</span></span>
                                    </label>
                                </div>
                                <FormError v-if="itemForm.errors.care_requires_allergy_check">{{ itemForm.errors.care_requires_allergy_check }}</FormError>
                                <FormError v-if="itemForm.errors.care_recommends_vitals">{{ itemForm.errors.care_recommends_vitals }}</FormError>
                                <FormError v-if="itemForm.errors.clinician_orderable">{{ itemForm.errors.clinician_orderable }}</FormError>
                            </div>
                            <div v-if="itemForm.billable" class="rounded border border-gray-200 p-3 dark:border-gray-800 sm:col-span-2">
                                <label for="catalog_staff_policy" class="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Politique Avantage Personnel <span class="text-red-500">*</span></label>
                                <select id="catalog_staff_policy" v-model="itemForm.staff_coverage_policy" class="block h-9 w-full max-w-xl rounded border-gray-200 bg-white py-1.5 ps-3 pe-9 text-sm text-slate-700 focus:border-primary-500 focus:ring-primary-200 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                                    <option v-for="policy in staffCoveragePolicies" :key="policy.value" :value="policy.value">{{ policy.label }}</option>
                                </select>
                                <p class="mt-1 text-xs text-slate-400">Classification explicite : le nom, le code et le module Chirurgie ne déclenchent jamais le crédit Bloc.</p>
                                <FormError v-if="itemForm.errors.staff_coverage_policy">{{ itemForm.errors.staff_coverage_policy }}</FormError>
                            </div>
                        </div>

                        <div>
                            <label for="catalog_description" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Description <span class="font-normal text-slate-400">(facultatif)</span></label>
                            <textarea id="catalog_description" v-model="itemForm.description" rows="3" class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950"></textarea>
                            <FormError v-if="itemForm.errors.description">{{ itemForm.errors.description }}</FormError>
                        </div>

                        <div v-if="!isEditing && itemForm.billable" class="border-t border-gray-200 pt-5 dark:border-gray-900">
                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Barèmes initiaux</h3>
                            <p class="mt-1 text-xs text-slate-400">Les deux tarifs sont indépendants et restent historisés. Le tarif mutuelle peut être complété après création.</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="catalog_tariff" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Tarif sans mutuelle (Ar) <span class="text-red-500">*</span></label>
                                    <Input id="catalog_tariff" v-model="itemForm.tariff_amount" type="number" min="0.01" step="0.01" :aria-invalid="Boolean(itemForm.errors.tariff_amount)" />
                                    <FormError v-if="itemForm.errors.tariff_amount">{{ itemForm.errors.tariff_amount }}</FormError>
                                </div>
                                <div>
                                    <label for="catalog_mutual_tariff" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Tarif mutuelle (Ar) <span class="font-normal text-slate-400">(facultatif)</span></label>
                                    <Input id="catalog_mutual_tariff" v-model="itemForm.mutual_tariff_amount" type="number" min="0.01" step="0.01" :aria-invalid="Boolean(itemForm.errors.mutual_tariff_amount)" />
                                    <FormError v-if="itemForm.errors.mutual_tariff_amount">{{ itemForm.errors.mutual_tariff_amount }}</FormError>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="catalog_tariff_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label>
                                    <Input id="catalog_tariff_reason" v-model="itemForm.tariff_reason" placeholder="Tarif initial validé" :aria-invalid="Boolean(itemForm.errors.tariff_reason)" />
                                    <FormError v-if="itemForm.errors.tariff_reason">{{ itemForm.errors.tariff_reason }}</FormError>
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="flex flex-col-reverse gap-3 border-t border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:justify-end">
                        <Button size="rg" variant="white-outline" type="button" :disabled="itemForm.processing" @click="closeItemForm">Annuler</Button>
                        <Button size="rg" variant="primary" type="submit" :disabled="itemForm.processing"><Icon class="text-lg" name="check" /><span class="ms-2">{{ itemForm.processing ? 'Enregistrement…' : (isEditing ? 'Enregistrer' : 'Créer l’élément') }}</span></Button>
                    </footer>
                </form>
            </section>
        </div>

        <div v-if="tariffTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeTariff">
            <section class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="tariff-form-title">
                <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                    <div><h2 id="tariff-form-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Barèmes — {{ tariffTarget.name }}</h2><p class="mt-1 text-xs text-slate-500">Chaque grille possède son montant actif et son propre historique.</p></div>
                    <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white" aria-label="Fermer" @click="closeTariff"><Icon class="text-xl" name="cross" /></button>
                </header>
                <form class="overflow-y-auto" @submit.prevent="submitTariff">
                    <div class="space-y-5 p-5">
                        <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Choisir le barème">
                            <button
                                v-for="category in tariffCategories"
                                :key="category.value"
                                type="button"
                                role="radio"
                                :aria-checked="tariffForm.tariff_category === category.value"
                                :class="['rounded-md border p-3 text-start transition-colors', tariffForm.tariff_category === category.value ? 'border-primary-500 bg-primary-50/40 dark:bg-primary-950/20' : 'border-gray-200 hover:border-slate-300 dark:border-gray-800']"
                                @click="chooseTariffCategory(category.value)"
                            >
                                <span class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-bold text-slate-700 dark:text-white">{{ category.label }}</span>
                                    <Icon v-if="tariffForm.tariff_category === category.value" class="text-primary-600" name="check-circle" />
                                </span>
                                <span class="mt-2 block text-base font-bold text-slate-800 dark:text-white">
                                    {{ (category.value === 'MUTUAL' ? tariffTarget.current_mutual_tariff : tariffTarget.current_standard_tariff)
                                        ? formatMoney(category.value === 'MUTUAL' ? tariffTarget.current_mutual_tariff.amount : tariffTarget.current_standard_tariff.amount)
                                        : 'Non configuré' }}
                                </span>
                                <span class="mt-0.5 block text-[11px] text-slate-400">{{ (category.value === 'MUTUAL' ? tariffTarget.current_mutual_tariff : tariffTarget.current_standard_tariff) ? 'Tarif actif' : 'À configurer' }}</span>
                            </button>
                        </div>

                        <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-gray-900">
                            <div><p class="text-sm font-bold text-slate-700 dark:text-white">{{ tariffCategories.find((category) => category.value === tariffForm.tariff_category)?.label }}</p><p class="mt-0.5 text-xs text-slate-400">Le montant prend effet immédiatement ; l’ancienne version reste conservée.</p></div>
                            <button v-if="selectedCurrentTariff && can('catalog.tariffs.archive')" type="button" class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-amber-700" @click="openArchive(tariffTarget, 'tariff', tariffForm.tariff_category)"><Icon name="pause" />Suspendre</button>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label for="new_tariff" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Nouveau montant (Ar) <span class="text-red-500">*</span></label><Input id="new_tariff" v-model="tariffForm.tariff_amount" type="number" min="0.01" step="0.01" :aria-invalid="Boolean(tariffForm.errors.tariff_amount)" /><FormError v-if="tariffForm.errors.tariff_amount">{{ tariffForm.errors.tariff_amount }}</FormError></div>
                            <div><label for="new_tariff_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif du changement <span class="text-red-500">*</span></label><Input id="new_tariff_reason" v-model="tariffForm.reason" placeholder="Décision tarifaire du…" :aria-invalid="Boolean(tariffForm.errors.reason)" /><FormError v-if="tariffForm.errors.reason">{{ tariffForm.errors.reason }}</FormError></div>
                        </div>
                        <div v-if="selectedTariffHistory.length" class="overflow-hidden rounded border border-gray-200 dark:border-gray-800">
                            <div class="border-b border-gray-200 bg-gray-50/70 px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:border-gray-800 dark:bg-gray-900/40">Historique — {{ tariffCategories.find((category) => category.value === tariffForm.tariff_category)?.label }}</div>
                            <div v-for="tariff in selectedTariffHistory" :key="tariff.uuid" class="grid gap-1 border-b border-gray-100 px-4 py-3 last:border-0 dark:border-gray-900 sm:grid-cols-[150px_1fr_auto] sm:items-center">
                                <div><p class="text-sm font-bold text-slate-700 dark:text-white">{{ formatMoney(tariff.amount, tariff.currency) }}</p><span v-if="tariff.current" class="text-[11px] font-medium text-emerald-600">Actuel</span></div>
                                <div><p class="text-xs text-slate-500">{{ tariff.change_reason }}</p><p class="mt-0.5 text-[11px] text-slate-400">par {{ tariff.creator || 'Utilisateur' }}</p></div>
                                <div class="text-end text-[11px] text-slate-400"><p>{{ formatDateTime(tariff.effective_from) }}</p><p v-if="tariff.effective_until">au {{ formatDateTime(tariff.effective_until) }}</p></div>
                            </div>
                        </div>
                    </div>
                    <footer class="flex flex-col-reverse gap-3 border-t border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:justify-end"><Button size="rg" variant="white-outline" type="button" :disabled="tariffForm.processing" @click="closeTariff">Annuler</Button><Button size="rg" variant="primary" type="submit" :disabled="tariffForm.processing"><Icon class="text-lg" name="check" /><span class="ms-2">{{ tariffForm.processing ? 'Enregistrement…' : 'Appliquer le tarif' }}</span></Button></footer>
                </form>
            </section>
        </div>

        <div v-if="archiveTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeArchive">
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="archive-catalog-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300"><Icon class="text-xl" :name="archiveMode === 'tariff' ? 'pause' : 'archive'" /></span>
                    <div><h2 id="archive-catalog-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">{{ archiveMode === 'tariff' ? `Suspendre le tarif ${archiveTariffLabel} ?` : 'Archiver cet élément ?' }}</h2><p class="mt-1 text-sm leading-5 text-slate-500">{{ archiveMode === 'tariff' ? `Seule la grille ${archiveTariffLabel} sera suspendue. L’autre tarif et tout l’historique restent inchangés.` : 'Il disparaîtra des sélections opérationnelles, sans effacer son historique.' }}</p></div>
                </div>
                <div class="mt-5"><label for="catalog_archive_reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label><textarea id="catalog_archive_reason" v-model="archiveForm.reason" rows="3" autofocus class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950" placeholder="Décision et référence utiles"></textarea><FormError v-if="archiveForm.errors.reason">{{ archiveForm.errors.reason }}</FormError></div>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><Button size="rg" variant="white-outline" type="button" :disabled="archiveForm.processing" @click="closeArchive">Annuler</Button><Button size="rg" variant="secondary" type="button" :disabled="archiveForm.processing" @click="submitArchive"><Icon class="text-lg" :name="archiveMode === 'tariff' ? 'pause' : 'archive'" /><span class="ms-2">{{ archiveForm.processing ? 'Enregistrement…' : 'Confirmer' }}</span></Button></div>
            </section>
        </div>

        <div v-if="reviewTarget" class="fixed inset-0 z-[1200] flex items-center justify-center bg-slate-950/50 p-4" role="presentation" @click.self="closeReview">
            <section class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="review-medicine-title">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"><Icon class="text-xl" name="check" /></span>
                    <div><h2 id="review-medicine-title" class="font-heading text-lg font-bold text-slate-700 dark:text-white">Marquer « {{ reviewTarget.medication_name }} » traité</h2><p class="mt-1 text-sm leading-5 text-slate-500">Décrivez la suite donnée (ajout au référentiel sous tel code, doublon, non retenu…). Cela ne crée ni médicament ni stock automatiquement.</p></div>
                </div>
                <div class="mt-5"><label for="catalog_review_note" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Note <span class="text-red-500">*</span></label><textarea id="catalog_review_note" v-model="reviewForm.note" rows="3" autofocus class="block w-full resize-y rounded border border-gray-200 bg-white px-4 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:focus:ring-primary-950" placeholder="Ex. Ajouté au référentiel sous MED-0231"></textarea><FormError v-if="reviewForm.errors.note">{{ reviewForm.errors.note }}</FormError></div>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><Button size="rg" variant="white-outline" type="button" :disabled="reviewForm.processing" @click="closeReview">Annuler</Button><Button size="rg" type="button" :disabled="reviewForm.processing" @click="submitReview"><Icon class="text-lg" name="check" /><span class="ms-2">{{ reviewForm.processing ? 'Enregistrement…' : 'Confirmer' }}</span></Button></div>
            </section>
        </div>
    </div>
</template>
