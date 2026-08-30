<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const restoring = ref(null);
const filtersProcessing = ref(false);
const restoreForm = useForm({});
const filterValues = reactive({
    search: props.filters.search ?? '',
    site: props.filters.site ?? 'ALL',
    category: props.filters.category ?? 'ALL',
    deleted_from: props.filters.deleted_from ?? '',
    deleted_to: props.filters.deleted_to ?? '',
});
let filterRequestId = 0;

const selectedSites = computed(() => filterValues.site !== 'ALL'
    ? props.sites.filter((site) => site.site.code === filterValues.site)
    : props.sites);
const records = computed(() => selectedSites.value
    .filter((site) => site.ok)
    .flatMap((site) => (site.data ?? []).map((record) => ({
        ...record,
        site: site.site,
    })))
    .sort((left, right) => new Date(right.deleted_at) - new Date(left.deleted_at)));
const unavailableSites = computed(() => selectedSites.value.filter((site) => !site.ok));
const categoryCounts = computed(() => Object.fromEntries(props.categories.map((category) => [
    category.code,
    selectedSites.value.reduce(
        (total, site) => total + (site.ok ? Number(site.meta?.summary?.categories?.[category.code] ?? 0) : 0),
        0,
    ),
])));
const total = computed(() => Object.values(categoryCounts.value).reduce((sum, count) => sum + count, 0));
const matchingTotal = computed(() => filterValues.category !== 'ALL'
    ? Number(categoryCounts.value[filterValues.category] ?? 0)
    : total.value);
const resultIsLimited = computed(() => selectedSites.value.some((site) => site.meta?.summary?.limited));

const applyFilters = () => {
    const requestId = ++filterRequestId;
    filtersProcessing.value = true;
    router.get('/super-admin/trash', {
        search: filterValues.search || undefined,
        site: filterValues.site,
        category: filterValues.category,
        deleted_from: filterValues.deleted_from || undefined,
        deleted_to: filterValues.deleted_to || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            if (requestId === filterRequestId) filtersProcessing.value = false;
        },
    });
};

const selectFilter = (field, value) => {
    filterValues[field] = value;
    applyFilters();
};
const clearFilters = () => {
    Object.assign(filterValues, {
        search: '',
        site: 'ALL',
        category: 'ALL',
        deleted_from: '',
        deleted_to: '',
    });
    applyFilters();
};
const openRestore = (record) => {
    restoring.value = record;
    restoreForm.clearErrors();
};
const closeRestore = () => {
    if (restoreForm.processing) return;
    restoring.value = null;
    restoreForm.clearErrors();
};
const submitRestore = () => restoreForm.post(
    `/super-admin/trash/${restoring.value.site.code}/${restoring.value.category}/${restoring.value.uuid}/restore`,
    {
        preserveScroll: true,
        onSuccess: closeRestore,
    },
);

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-MG', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    : '—';
</script>

<template>
    <Head title="Corbeille" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400">
                    <Icon class="text-xl" name="trash" />
                </span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Corbeille</h1>
                    <p class="mt-1 text-sm text-slate-500">Dossiers et référentiels archivés sur les sites cliniques.</p>
                </div>
            </div>
            <div class="max-w-xl border-s-2 border-amber-300 ps-3 text-xs leading-5 text-slate-500 dark:border-amber-700">
                La restauration conserve l’UUID et l’historique. Elle est exécutée par l’API du site concerné et inscrite dans son journal d’audit.
            </div>
        </header>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <button
                v-for="category in categories"
                :key="category.code"
                type="button"
                :class="['flex min-w-0 items-center gap-3 rounded-lg border bg-white px-4 py-3 text-start transition-colors dark:bg-gray-950', filterValues.category === category.code ? 'border-primary-300 ring-1 ring-primary-100 dark:border-primary-800 dark:ring-primary-950' : 'border-gray-200 hover:border-primary-200 dark:border-gray-900 dark:hover:border-primary-900']"
                @click="selectFilter('category', filterValues.category === category.code ? 'ALL' : category.code)"
            >
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400">
                    <Icon class="text-lg" :name="category.icon" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-xs font-medium text-slate-500">{{ category.label }}</p>
                    <p class="mt-0.5 text-xl font-bold tabular-nums text-slate-700 dark:text-white">{{ categoryCounts[category.code] ?? 0 }}</p>
                </div>
            </button>
        </section>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <form class="border-b border-gray-200 p-4 dark:border-gray-900" @submit.prevent="applyFilters">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(260px,1fr)_190px_210px_150px_150px_auto] xl:items-end">
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300">Recherche</span>
                        <span class="relative block">
                            <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                            <input v-model="filterValues.search" name="search" type="search" class="h-10 w-full rounded border border-gray-200 bg-white ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Nom, numéro patient, code…">
                        </span>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300">Site</span>
                        <select name="site" :value="filterValues.site" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white" @change="selectFilter('site', $event.target.value)">
                            <option value="ALL">Tous les sites</option>
                            <option v-for="site in sites" :key="site.site.code" :value="site.site.code">{{ site.site.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300">Catégorie</span>
                        <select name="category" :value="filterValues.category" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white" @change="selectFilter('category', $event.target.value)">
                            <option value="ALL">Toutes les catégories</option>
                            <option v-for="category in categories" :key="category.code" :value="category.code">{{ category.label }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300">Du</span>
                        <input v-model="filterValues.deleted_from" name="deleted_from" type="date" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300">Au</span>
                        <input v-model="filterValues.deleted_to" name="deleted_to" type="date" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                    </label>
                    <div class="flex h-10 items-center gap-2">
                        <Button size="rg" type="submit" :disabled="filtersProcessing"><Icon :class="{ 'animate-spin': filtersProcessing }" :name="filtersProcessing ? 'loader' : 'filter'" /><span class="ms-2">{{ filtersProcessing ? 'Actualisation…' : 'Filtrer' }}</span></Button>
                        <button v-if="filterValues.search || filterValues.category !== 'ALL' || filterValues.site !== 'ALL' || filterValues.deleted_from || filterValues.deleted_to" type="button" class="h-10 px-2 text-xs font-bold text-slate-500 hover:text-slate-700 dark:hover:text-white" @click="clearFilters">Effacer</button>
                    </div>
                </div>
            </form>

            <div v-for="site in unavailableSites" :key="site.site.code" class="flex items-start gap-3 border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs text-amber-800 last:border-b-0 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                <Icon class="mt-0.5 shrink-0" name="server" />
                <p><strong>{{ site.site.name }} :</strong> {{ site.message }}</p>
            </div>

            <div class="hidden grid-cols-[minmax(240px,1.25fr)_190px_160px_210px_minmax(220px,1fr)_110px] border-b border-gray-200 bg-gray-50 px-5 py-3 text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900 dark:bg-gray-1000 lg:grid">
                <span>Élément</span><span>Catégorie</span><span>Site</span><span>Suppression</span><span>Motif</span><span class="text-end">Action</span>
            </div>

            <div v-if="records.length" class="divide-y divide-gray-200 dark:divide-gray-900">
                <article v-for="record in records" :key="`${record.site.code}-${record.category}-${record.uuid}`" class="grid gap-3 px-5 py-4 lg:grid-cols-[minmax(240px,1.25fr)_190px_160px_210px_minmax(220px,1fr)_110px] lg:items-center lg:gap-0">
                    <div class="min-w-0 pe-4">
                        <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ record.title }}</p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-400">
                            <span v-if="record.reference" class="font-mono">{{ record.reference }}</span>
                            <span>{{ record.subtitle }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2 pe-4 text-xs font-medium text-slate-600 dark:text-slate-300">
                        <Icon class="text-base text-slate-400" :name="record.category_icon" />
                        <span>{{ record.category_label }}</span>
                    </div>
                    <div class="pe-4 text-xs text-slate-600 dark:text-slate-300">
                        <p class="font-medium">{{ record.site.name }}</p>
                        <p class="mt-0.5 font-mono text-[10px] text-slate-400">{{ record.site.code }}</p>
                    </div>
                    <div class="pe-4 text-xs text-slate-500">
                        <p>{{ formatDateTime(record.deleted_at) }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-slate-400">par {{ record.deleted_by }}</p>
                    </div>
                    <p class="line-clamp-2 pe-4 text-xs leading-5 text-slate-500" :title="record.delete_reason">{{ record.delete_reason || 'Aucun motif renseigné' }}</p>
                    <div class="flex justify-end">
                        <Button v-if="can('trash.restore') && record.can_restore" size="sm" variant="white-outline" type="button" @click="openRestore(record)">
                            <Icon name="undo" /><span class="ms-2">Restaurer</span>
                        </Button>
                    </div>
                </article>
            </div>

            <div v-else-if="!unavailableSites.length || selectedSites.some((site) => site.ok)" class="flex min-h-52 flex-col items-center justify-center px-6 py-10 text-center">
                <Icon class="text-3xl text-slate-300" name="trash" />
                <h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Aucun élément dans cette sélection</h2>
                <p class="mt-1 text-xs text-slate-500">Modifiez les filtres ou choisissez un autre site.</p>
            </div>

            <footer class="flex flex-col gap-1 border-t border-gray-200 px-5 py-3 text-xs text-slate-400 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                <span>{{ records.length }} élément(s) affiché(s) · {{ matchingTotal }} correspondant(s) sur les sites joignables</span>
                <span v-if="resultIsLimited">Les 100 suppressions les plus récentes de chaque site sont affichées.</span>
            </footer>
        </section>

        <div v-if="restoring" class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true" @click.self="closeRestore">
            <form class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submitRestore">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300"><Icon class="text-lg" name="undo" /></span>
                    <div>
                        <h2 class="text-base font-bold text-slate-700 dark:text-white">Restaurer cet élément ?</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500"><strong>{{ restoring.title }}</strong> redeviendra disponible sur {{ restoring.site.name }}.</p>
                    </div>
                </div>
                <dl class="mt-5 divide-y divide-gray-200 rounded border border-gray-200 px-3 text-xs dark:divide-gray-800 dark:border-gray-800">
                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-400">Catégorie</dt><dd class="font-medium text-slate-600 dark:text-slate-300">{{ restoring.category_label }}</dd></div>
                    <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-400">Supprimé le</dt><dd class="text-end font-medium text-slate-600 dark:text-slate-300">{{ formatDateTime(restoring.deleted_at) }}</dd></div>
                    <div class="py-2.5"><dt class="text-slate-400">Motif initial</dt><dd class="mt-1 leading-5 text-slate-600 dark:text-slate-300">{{ restoring.delete_reason || 'Aucun motif renseigné' }}</dd></div>
                </dl>
                <p v-for="error in Object.values(restoreForm.errors)" :key="error" class="mt-3 text-xs text-red-600">{{ error }}</p>
                <div class="mt-5 flex justify-end gap-3">
                    <Button size="rg" variant="white-outline" type="button" @click="closeRestore">Annuler</Button>
                    <Button size="rg" :disabled="restoreForm.processing"><Icon name="undo" /><span class="ms-2">{{ restoreForm.processing ? 'Restauration…' : 'Confirmer' }}</span></Button>
                </div>
            </form>
        </div>
    </div>
</template>
