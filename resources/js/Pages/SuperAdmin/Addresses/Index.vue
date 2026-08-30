<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({ sites: Array, filters: Object });
const { can } = usePermissions();
const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const showCreate = ref(false);
const showImport = ref(false);
const editing = ref(null);
const archiving = ref(null);
const selectedUuids = ref(new Set());
const bulkAction = ref(null);
const createForm = useForm({ site_code: selectedSiteCode.value, label: '' });
const editForm = useForm({ label: '' });
const archiveForm = useForm({ reason: '' });
const importForm = useForm({ site_code: selectedSiteCode.value, file: null });
const bulkForm = useForm({ site_code: selectedSiteCode.value, uuids: [], reason: '' });

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const entries = computed(() => selectedSite.value?.data ?? []);
const selectedEntries = computed(() => entries.value.filter((entry) => selectedUuids.value.has(entry.uuid)));
const selectedActiveEntries = computed(() => selectedEntries.value.filter((entry) => entry.active));
const selectedArchivedEntries = computed(() => selectedEntries.value.filter((entry) => !entry.active));
const allVisibleSelected = computed(() => entries.value.length > 0 && entries.value.every((entry) => selectedUuids.value.has(entry.uuid)));
const total = computed(() => props.sites.reduce((sum, site) => sum + (site.meta?.summary?.displayed ?? 0), 0));
const exportUrl = computed(() => {
    const params = new URLSearchParams({ site_code: selectedSiteCode.value, status: props.filters.status ?? 'ACTIVE' });
    if (props.filters.search) params.set('search', props.filters.search);
    return `/super-admin/addresses/export?${params.toString()}`;
});
const selectedExportUrl = computed(() => {
    const params = new URLSearchParams({ site_code: selectedSiteCode.value, status: props.filters.status ?? 'ACTIVE' });
    if (props.filters.search) params.set('search', props.filters.search);
    selectedEntries.value.forEach((entry) => params.append('uuids[]', entry.uuid));
    return `/super-admin/addresses/export?${params.toString()}`;
});

const toggleEntry = (uuid) => {
    const next = new Set(selectedUuids.value);
    next.has(uuid) ? next.delete(uuid) : next.size < 100 && next.add(uuid);
    selectedUuids.value = next;
};

const toggleAllVisible = () => {
    const next = new Set(selectedUuids.value);
    if (allVisibleSelected.value) entries.value.forEach((entry) => next.delete(entry.uuid));
    else entries.value.forEach((entry) => { if (next.size < 100) next.add(entry.uuid); });
    selectedUuids.value = next;
};

const clearSelection = () => { selectedUuids.value = new Set(); };

const selectSite = (code) => {
    selectedSiteCode.value = code;
    createForm.site_code = code;
    importForm.site_code = code;
    showCreate.value = false;
    showImport.value = false;
    editing.value = null;
    clearSelection();
};

const applyFilters = (event) => {
    const form = new FormData(event.currentTarget);
    router.get('/super-admin/addresses', { search: form.get('search'), status: form.get('status') }, { preserveState: true, replace: true });
};

const submitCreate = () => createForm.post('/super-admin/addresses', {
    preserveScroll: true,
    onSuccess: () => { createForm.reset('label'); showCreate.value = false; },
});

const startEdit = (entry) => {
    editing.value = entry;
    editForm.label = entry.label;
    editForm.clearErrors();
};

const submitEdit = () => editForm.put(`/super-admin/addresses/${selectedSiteCode.value}/${editing.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editing.value = null; editForm.reset(); },
});

const openArchive = (entry) => { archiving.value = entry; archiveForm.reset(); archiveForm.clearErrors(); };
const submitArchive = () => archiveForm.delete(`/super-admin/addresses/${selectedSiteCode.value}/${archiving.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { archiving.value = null; archiveForm.reset(); },
});
const restore = (entry) => router.post(`/super-admin/addresses/${selectedSiteCode.value}/${entry.uuid}/restore`, {}, { preserveScroll: true });
const openBulkAction = (mode) => {
    const targets = mode === 'ARCHIVE' ? selectedActiveEntries.value : selectedArchivedEntries.value;
    if (!targets.length) return;
    bulkForm.reset();
    bulkForm.clearErrors();
    bulkForm.site_code = selectedSiteCode.value;
    bulkForm.uuids = targets.map((entry) => entry.uuid);
    bulkAction.value = { mode, count: targets.length };
};
const closeBulkAction = () => {
    if (bulkForm.processing) return;
    bulkAction.value = null;
    bulkForm.reset();
};
const submitBulkAction = () => bulkForm.post(
    `/super-admin/addresses/bulk/${bulkAction.value.mode === 'ARCHIVE' ? 'archive' : 'restore'}`,
    {
        preserveScroll: true,
        onSuccess: () => { closeBulkAction(); clearSelection(); },
    },
);
const submitImport = () => importForm.post('/super-admin/addresses/import', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { importForm.reset('file'); showImport.value = false; },
});
</script>

<template>
    <Head title="Référentiel des adresses" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-start gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" name="map-pin" /></span><div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Référentiel des adresses</h1><p class="mt-1 text-sm text-slate-500">Localités proposées à la Réception et dans les dossiers patients, séparées par site.</p></div></div>
            <div class="flex flex-wrap items-center gap-2"><Button v-if="can('address_entries.export') && selectedSite?.ok" as="a" :href="exportUrl" size="rg" variant="white-outline"><Icon class="text-lg" name="download" /><span class="ms-2">Exporter Excel</span></Button><Button v-else-if="can('address_entries.export')" size="rg" variant="white-outline" type="button" disabled title="Configurez et connectez l’API du site pour exporter"><Icon class="text-lg" name="download" /><span class="ms-2">Exporter Excel</span></Button><Button v-if="can('address_entries.import')" size="rg" variant="white-outline" type="button" @click="showImport = !showImport"><Icon class="text-lg" :name="showImport ? 'cross' : 'upload'" /><span class="ms-2">{{ showImport ? 'Fermer' : 'Importer Excel' }}</span></Button><Button v-if="can('address_entries.create')" size="rg" type="button" :disabled="!selectedSite?.ok" :title="selectedSite?.ok ? 'Ajouter une adresse' : 'Configurez et connectez l’API du site pour ajouter une adresse'" @click="showCreate = !showCreate"><Icon class="text-lg" :name="showCreate ? 'cross' : 'plus'" /><span class="ms-2">{{ showCreate ? 'Fermer' : 'Nouvelle adresse' }}</span></Button></div>
        </header>

        <form v-if="showImport" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submitImport"><p v-if="!selectedSite?.ok" class="mb-4 flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><Icon name="info" />Vous pouvez préparer le fichier, mais l’import sera disponible après la configuration de l’API du site.</p><div class="flex flex-col gap-4 lg:flex-row lg:items-end"><div class="min-w-52"><label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Site destinataire <span class="text-red-500">*</span></label><select v-model="importForm.site_code" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option v-for="site in sites" :key="site.site.code" :value="site.site.code" :disabled="!site.ok">{{ site.site.name }}{{ site.ok ? '' : ' — indisponible' }}</option></select></div><div class="min-w-0 flex-1"><div class="mb-1.5 flex items-center justify-between gap-3"><label class="block text-sm font-medium text-slate-700 dark:text-white">Fichier Excel <span class="text-red-500">*</span></label><a href="/super-admin/addresses/import-template" class="text-xs font-bold text-primary-600 hover:text-primary-700">Télécharger le modèle Excel</a></div><input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block h-10 w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm text-slate-600 file:me-3 file:border-0 file:bg-transparent file:text-xs file:font-bold file:text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" @change="importForm.file = $event.target.files[0] ?? null"><p class="mt-1 text-xs text-slate-400">Une colonne « Adresse » · format .xlsx · 1 000 adresses maximum · 5 Mo.</p><p v-for="error in Object.values(importForm.errors)" :key="error" class="mt-1 text-xs text-red-600">{{ error }}</p></div><Button size="rg" :disabled="importForm.processing || !selectedSite?.ok"><Icon class="text-lg" name="upload" /><span class="ms-2">{{ importForm.processing ? 'Importation…' : 'Importer les adresses' }}</span></Button></div></form>

        <form v-if="showCreate" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submitCreate"><div class="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-end"><div><label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Site <span class="text-red-500">*</span></label><select v-model="createForm.site_code" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option v-for="site in sites" :key="site.site.code" :value="site.site.code" :disabled="!site.ok">{{ site.site.name }}{{ site.ok ? '' : ' — indisponible' }}</option></select></div><div><label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Libellé de l’adresse <span class="text-red-500">*</span></label><input v-model="createForm.label" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Ex. Ambondromamy centre"><p v-if="createForm.errors.label" class="mt-1 text-xs text-red-600">{{ createForm.errors.label }}</p><p v-if="createForm.errors.site_code" class="mt-1 text-xs text-red-600">{{ createForm.errors.site_code }}</p></div><Button size="rg" :disabled="createForm.processing"><Icon class="text-lg" name="check" /><span class="ms-2">Enregistrer</span></Button></div></form>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 xl:flex-row xl:items-center xl:justify-between">
                <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded bg-gray-100 p-1 dark:bg-gray-900"><button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold', selectedSiteCode === site.site.code ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500']" @click="selectSite(site.site.code)"><span :class="['h-1.5 w-1.5 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'OFFLINE' || site.status === 'ERROR' ? 'bg-red-500' : 'bg-slate-300']" />{{ site.site.name }}<span v-if="site.ok" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] dark:bg-gray-900">{{ site.meta?.summary?.displayed ?? 0 }}</span></button></div>
                <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="applyFilters"><label class="relative block sm:w-72"><Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" /><input name="search" :value="filters.search" type="search" class="h-9 w-full rounded border border-gray-200 bg-white ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Rechercher une adresse"></label><select name="status" :value="filters.status" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="ACTIVE">Actives</option><option value="ARCHIVED">Archivées</option><option value="ALL">Toutes</option></select><Button size="sm" variant="white-outline">Filtrer</Button></form>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center"><span class="flex h-11 w-11 items-center justify-center rounded bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="server" /></span><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Référentiel indisponible pour {{ selectedSite?.site.name }}</h2><p class="mt-1 max-w-lg text-xs leading-5 text-slate-500">{{ selectedSite?.message }}</p></div>

            <div v-else>
                <div v-if="selectedEntries.length" class="flex flex-col gap-3 border-b border-gray-200 bg-primary-50/60 px-5 py-3 dark:border-gray-900 dark:bg-primary-950/10 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><span class="flex h-8 min-w-8 items-center justify-center rounded bg-primary-600 px-2 text-xs font-bold text-white">{{ selectedEntries.length }}</span><div><p class="text-sm font-bold text-slate-700 dark:text-white">adresse(s) sélectionnée(s)</p><p class="text-xs text-slate-500">Actions limitées au site {{ selectedSite.site.name }} · 100 maximum</p></div></div><div class="flex flex-wrap items-center gap-2"><Button v-if="can('address_entries.export')" as="a" :href="selectedExportUrl" size="sm" variant="white-outline"><Icon name="download" /><span class="ms-2">Exporter</span></Button><Button v-if="can('address_entries.archive') && selectedActiveEntries.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('ARCHIVE')"><Icon name="archive" /><span class="ms-2">Archiver ({{ selectedActiveEntries.length }})</span></Button><Button v-if="can('address_entries.restore') && selectedArchivedEntries.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('RESTORE')"><Icon name="undo" /><span class="ms-2">Restaurer ({{ selectedArchivedEntries.length }})</span></Button><button type="button" class="px-2 py-1 text-xs font-bold text-slate-500 hover:text-slate-700 dark:hover:text-white" @click="clearSelection">Désélectionner</button></div></div>
                <div class="grid grid-cols-[36px_minmax(0,1fr)_150px_120px] border-b border-gray-200 bg-gray-50 px-5 py-3 text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:border-gray-900 dark:bg-gray-1000"><span><input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="allVisibleSelected" aria-label="Sélectionner toutes les adresses affichées" @change="toggleAllVisible"></span><span>Adresse</span><span>État</span><span class="text-end">Actions</span></div>
                <div v-for="entry in entries" :key="entry.uuid" :class="['grid grid-cols-[36px_minmax(0,1fr)_150px_120px] items-center border-b border-gray-200 px-5 py-3 last:border-0 dark:border-gray-900', selectedUuids.has(entry.uuid) ? 'bg-primary-50/30 dark:bg-primary-950/10' : '']"><div><input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="selectedUuids.has(entry.uuid)" :aria-label="`Sélectionner ${entry.label}`" @change="toggleEntry(entry.uuid)"></div><div v-if="editing?.uuid === entry.uuid" class="me-4"><input v-model="editForm.label" class="h-9 w-full rounded border border-primary-400 bg-white px-3 text-sm text-slate-700 outline-none ring-2 ring-primary-100 dark:bg-gray-950 dark:text-white"><p v-if="editForm.errors.label" class="mt-1 text-xs text-red-600">{{ editForm.errors.label }}</p></div><div v-else><p class="font-medium text-slate-700 dark:text-white">{{ entry.label }}</p><p class="mt-0.5 font-mono text-[10px] text-slate-400">{{ entry.uuid }}</p></div><span><span :class="['inline-flex rounded border px-2 py-1 text-[11px] font-bold', entry.active ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400']">{{ entry.active ? 'Active' : 'Archivée' }}</span></span><div class="flex justify-end gap-2"><template v-if="editing?.uuid === entry.uuid"><button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-primary-200 text-primary-600" title="Enregistrer" :disabled="editForm.processing" @click="submitEdit"><Icon name="check" /></button><button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 dark:border-gray-800" title="Annuler" @click="editing = null"><Icon name="cross" /></button></template><template v-else-if="entry.active"><button v-if="can('address_entries.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Modifier" @click="startEdit(entry)"><Icon name="edit" /></button><button v-if="can('address_entries.archive')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900" title="Archiver" @click="openArchive(entry)"><Icon name="archive" /></button></template><button v-else-if="can('address_entries.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-gray-200 text-slate-500 hover:text-primary-600 dark:border-gray-800" title="Restaurer" @click="restore(entry)"><Icon name="undo" /></button></div></div>
                <div v-if="!entries.length" class="px-5 py-12 text-center"><Icon class="text-2xl text-slate-300" name="map-pin" /><p class="mt-2 text-sm text-slate-400">Aucune adresse ne correspond à ces filtres.</p></div>
                <div class="flex items-center justify-between border-t border-gray-200 px-5 py-3 text-xs text-slate-400 dark:border-gray-900"><span>{{ entries.length }} affichée(s) sur ce site</span><span>{{ total }} résultat(s) sur les sites joignables</span></div>
            </div>
        </section>

        <div v-if="archiving" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true"><form class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submitArchive"><div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-red-50 text-red-600 dark:bg-red-950/30"><Icon class="text-lg" name="archive" /></span><div><h2 class="text-base font-bold text-slate-700 dark:text-white">Archiver cette adresse ?</h2><p class="mt-1 text-sm text-slate-500">{{ archiving.label }} ne sera plus proposée dans les nouveaux dossiers. Les patients existants restent inchangés.</p></div></div><label class="mb-1.5 mt-5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></label><textarea v-model="archiveForm.reason" rows="3" class="w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Pourquoi cette adresse doit-elle être archivée ?"></textarea><p v-if="archiveForm.errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm.errors.reason }}</p><div class="mt-5 flex justify-end gap-3"><Button size="rg" variant="white-outline" type="button" @click="archiving = null">Annuler</Button><Button size="rg" variant="danger" :disabled="archiveForm.processing"><Icon class="text-lg" name="archive" /><span class="ms-2">Archiver</span></Button></div></form></div>
        <div v-if="bulkAction" class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true" @click.self="closeBulkAction"><form class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-950" @submit.prevent="submitBulkAction"><div class="flex items-start gap-3"><span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded', bulkAction.mode === 'ARCHIVE' ? 'bg-red-50 text-red-600 dark:bg-red-950/30' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30']"><Icon class="text-lg" :name="bulkAction.mode === 'ARCHIVE' ? 'archive' : 'undo'" /></span><div><h2 class="text-base font-bold text-slate-700 dark:text-white">{{ bulkAction.mode === 'ARCHIVE' ? 'Archiver' : 'Restaurer' }} {{ bulkAction.count }} adresse(s) ?</h2><p class="mt-1 text-sm leading-5 text-slate-500">L’opération est atomique : si une adresse n’est plus compatible, aucune adresse ne sera modifiée.</p></div></div><template v-if="bulkAction.mode === 'ARCHIVE'"><label class="mb-1.5 mt-5 block text-sm font-medium text-slate-700 dark:text-white">Motif commun <span class="text-red-500">*</span></label><textarea v-model="bulkForm.reason" rows="3" class="w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Précisez la décision d’archivage"></textarea></template><p v-for="error in Object.values(bulkForm.errors)" :key="error" class="mt-2 text-xs text-red-600">{{ error }}</p><div class="mt-5 flex justify-end gap-3"><Button size="rg" variant="white-outline" type="button" @click="closeBulkAction">Annuler</Button><Button size="rg" :variant="bulkAction.mode === 'ARCHIVE' ? 'danger' : 'primary'" :disabled="bulkForm.processing"><Icon :name="bulkAction.mode === 'ARCHIVE' ? 'archive' : 'undo'" /><span class="ms-2">Confirmer</span></Button></div></form></div>
    </div>
</template>
