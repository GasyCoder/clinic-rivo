<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import { Archive, Check, Download, Info, MapPin, Pencil, Plus, RotateCcw, Search, Server, Upload, X } from 'lucide-vue-next';
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
            <div class="flex items-start gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground dark:text-muted-foreground"><MapPin class="h-5 w-5" /></span><div><p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Super Administration</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-foreground">Référentiel des adresses</h1><p class="mt-1 text-sm text-muted-foreground">Localités proposées à la Réception et dans les dossiers patients, séparées par site.</p></div></div>
            <div class="flex flex-wrap items-center gap-2"><Button v-if="can('address_entries.export') && selectedSite?.ok" as="a" :href="exportUrl" size="rg" variant="white-outline"><Download class="h-4.5 w-4.5" />Exporter Excel</Button><Button v-else-if="can('address_entries.export')" size="rg" variant="white-outline" type="button" disabled title="Configurez et connectez l’API du site pour exporter"><Download class="h-4.5 w-4.5" />Exporter Excel</Button><Button v-if="can('address_entries.import')" size="rg" variant="white-outline" type="button" @click="showImport = !showImport"><component class="h-4.5 w-4.5" :is="showImport ? X : Upload" />{{ showImport ? 'Fermer' : 'Importer Excel' }}</Button><Button v-if="can('address_entries.create')" size="rg" type="button" :disabled="!selectedSite?.ok" :title="selectedSite?.ok ? 'Ajouter une adresse' : 'Configurez et connectez l’API du site pour ajouter une adresse'" @click="showCreate = !showCreate"><component class="h-4.5 w-4.5" :is="showCreate ? X : Plus" />{{ showCreate ? 'Fermer' : 'Nouvelle adresse' }}</Button></div>
        </header>

        <form v-if="showImport" class="rounded-lg border border-border bg-card p-5" @submit.prevent="submitImport"><p v-if="!selectedSite?.ok" class="mb-4 flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><Info class="h-4 w-4" />Vous pouvez préparer le fichier, mais l’import sera disponible après la configuration de l’API du site.</p><div class="flex flex-col gap-4 lg:flex-row lg:items-end"><div class="min-w-52"><label class="mb-1.5 block text-sm font-medium text-foreground">Site destinataire <span class="text-red-500">*</span></label><select v-model="importForm.site_code" class="h-10 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><option v-for="site in sites" :key="site.site.code" :value="site.site.code" :disabled="!site.ok">{{ site.site.name }}{{ site.ok ? '' : ' — indisponible' }}</option></select></div><div class="min-w-0 flex-1"><div class="mb-1.5 flex items-center justify-between gap-3"><label class="block text-sm font-medium text-foreground">Fichier Excel <span class="text-red-500">*</span></label><a href="/super-admin/addresses/import-template" class="text-xs font-bold text-primary hover:text-primary">Télécharger le modèle Excel</a></div><input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block h-10 w-full rounded border border-border bg-card px-3 py-2 text-sm text-muted-foreground file:me-3 file:border-0 file:bg-transparent file:text-xs file:font-bold file:text-muted-foreground" @change="importForm.file = $event.target.files[0] ?? null"><p class="mt-1 text-xs text-muted-foreground">Une colonne « Adresse » · format .xlsx · 1 000 adresses maximum · 5 Mo.</p><p v-for="error in Object.values(importForm.errors)" :key="error" class="mt-1 text-xs text-red-600">{{ error }}</p></div><Button size="rg" :disabled="importForm.processing || !selectedSite?.ok"><Upload class="h-4.5 w-4.5" />{{ importForm.processing ? 'Importation…' : 'Importer les adresses' }}</Button></div></form>

        <form v-if="showCreate" class="rounded-lg border border-border bg-card p-5" @submit.prevent="submitCreate"><div class="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-end"><div><label class="mb-1.5 block text-sm font-medium text-foreground">Site <span class="text-red-500">*</span></label><select v-model="createForm.site_code" class="h-10 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><option v-for="site in sites" :key="site.site.code" :value="site.site.code" :disabled="!site.ok">{{ site.site.name }}{{ site.ok ? '' : ' — indisponible' }}</option></select></div><div><label class="mb-1.5 block text-sm font-medium text-foreground">Libellé de l’adresse <span class="text-red-500">*</span></label><input v-model="createForm.label" class="h-10 w-full rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Ex. Ambondromamy centre"><p v-if="createForm.errors.label" class="mt-1 text-xs text-red-600">{{ createForm.errors.label }}</p><p v-if="createForm.errors.site_code" class="mt-1 text-xs text-red-600">{{ createForm.errors.site_code }}</p></div><Button size="rg" :disabled="createForm.processing"><Check class="h-4.5 w-4.5" />Enregistrer</Button></div></form>

        <section class="overflow-hidden rounded-lg border border-border bg-card">
            <div class="flex flex-col gap-3 border-b border-border px-4 py-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded bg-muted p-1"><button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold', selectedSiteCode === site.site.code ? 'bg-card text-foreground shadow-sm ' : 'text-muted-foreground']" @click="selectSite(site.site.code)"><span :class="['h-1.5 w-1.5 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'OFFLINE' || site.status === 'ERROR' ? 'bg-red-500' : 'bg-muted-foreground/40']" />{{ site.site.name }}<span v-if="site.ok" class="rounded bg-muted px-1.5 py-0.5 text-[10px]">{{ site.meta?.summary?.displayed ?? 0 }}</span></button></div>
                <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="applyFilters"><label class="relative block sm:w-72"><Search class="pointer-events-none absolute inset-y-0 start-3 my-auto text-muted-foreground h-4.5 w-4.5" /><input name="search" :value="filters.search" type="search" class="h-9 w-full rounded border border-border bg-card ps-10 pe-3 text-sm text-foreground outline-none focus:border-primary focus:ring-2 focus:ring-ring/25" placeholder="Rechercher une adresse"></label><select name="status" :value="filters.status" class="h-9 rounded border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"><option value="ACTIVE">Actives</option><option value="ARCHIVED">Archivées</option><option value="ALL">Toutes</option></select><Button size="sm" variant="white-outline">Filtrer</Button></form>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center"><span class="flex h-11 w-11 items-center justify-center rounded bg-muted text-muted-foreground"><Server class="h-5 w-5" /></span><h2 class="mt-3 text-sm font-bold text-foreground">Référentiel indisponible pour {{ selectedSite?.site.name }}</h2><p class="mt-1 max-w-lg text-xs leading-5 text-muted-foreground">{{ selectedSite?.message }}</p></div>

            <div v-else>
                <div v-if="selectedEntries.length" class="flex flex-col gap-3 border-b border-border bg-primary/5 px-5 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><span class="flex h-8 min-w-8 items-center justify-center rounded bg-primary px-2 text-xs font-bold text-white">{{ selectedEntries.length }}</span><div><p class="text-sm font-bold text-foreground">adresse(s) sélectionnée(s)</p><p class="text-xs text-muted-foreground">Actions limitées au site {{ selectedSite.site.name }} · 100 maximum</p></div></div><div class="flex flex-wrap items-center gap-2"><Button v-if="can('address_entries.export')" as="a" :href="selectedExportUrl" size="sm" variant="white-outline"><Download class="h-4 w-4" />Exporter</Button><Button v-if="can('address_entries.archive') && selectedActiveEntries.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('ARCHIVE')"><Archive class="h-4 w-4" />Archiver ({{ selectedActiveEntries.length }})</Button><Button v-if="can('address_entries.restore') && selectedArchivedEntries.length" size="sm" variant="white-outline" type="button" @click="openBulkAction('RESTORE')"><RotateCcw class="h-4 w-4" />Restaurer ({{ selectedArchivedEntries.length }})</Button><button type="button" class="px-2 py-1 text-xs font-bold text-muted-foreground hover:text-foreground" @click="clearSelection">Désélectionner</button></div></div>
                <div class="grid grid-cols-[36px_minmax(0,1fr)_150px_120px] border-b border-border bg-muted px-5 py-3 text-[11px] font-medium uppercase tracking-wide text-muted-foreground"><span><input type="checkbox" class="rounded border-input text-primary focus:ring-ring" :checked="allVisibleSelected" aria-label="Sélectionner toutes les adresses affichées" @change="toggleAllVisible"></span><span>Adresse</span><span>État</span><span class="text-end">Actions</span></div>
                <div v-for="entry in entries" :key="entry.uuid" :class="['grid grid-cols-[36px_minmax(0,1fr)_150px_120px] items-center border-b border-border px-5 py-3 last:border-0 ', selectedUuids.has(entry.uuid) ? 'bg-primary/5 ' : '']"><div><input type="checkbox" class="rounded border-input text-primary focus:ring-ring" :checked="selectedUuids.has(entry.uuid)" :aria-label="`Sélectionner ${entry.label}`" @change="toggleEntry(entry.uuid)"></div><div v-if="editing?.uuid === entry.uuid" class="me-4"><input v-model="editForm.label" class="h-9 w-full rounded border border-primary bg-card px-3 text-sm text-foreground outline-none ring-2 ring-ring/25"><p v-if="editForm.errors.label" class="mt-1 text-xs text-red-600">{{ editForm.errors.label }}</p></div><div v-else><p class="font-medium text-foreground">{{ entry.label }}</p><p class="mt-0.5 font-mono text-[10px] text-muted-foreground">{{ entry.uuid }}</p></div><span><span :class="['inline-flex rounded border px-2 py-1 text-[11px] font-bold', entry.active ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-border bg-muted text-muted-foreground dark:text-muted-foreground']">{{ entry.active ? 'Active' : 'Archivée' }}</span></span><div class="flex justify-end gap-2"><template v-if="editing?.uuid === entry.uuid"><button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-primary/30 text-primary" title="Enregistrer" :disabled="editForm.processing" @click="submitEdit"><Check class="h-4 w-4" /></button><button type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground" title="Annuler" @click="editing = null"><X class="h-4 w-4" /></button></template><template v-else-if="entry.active"><button v-if="can('address_entries.update')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Modifier" @click="startEdit(entry)"><Pencil class="h-4 w-4" /></button><button v-if="can('address_entries.archive')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-red-200 text-red-500 hover:bg-red-50 dark:border-red-900" title="Archiver" @click="openArchive(entry)"><Archive class="h-4 w-4" /></button></template><button v-else-if="can('address_entries.restore')" type="button" class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground hover:text-primary" title="Restaurer" @click="restore(entry)"><RotateCcw class="h-4 w-4" /></button></div></div>
                <div v-if="!entries.length" class="px-5 py-12 text-center"><MapPin class="text-muted-foreground h-6 w-6" /><p class="mt-2 text-sm text-muted-foreground">Aucune adresse ne correspond à ces filtres.</p></div>
                <div class="flex items-center justify-between border-t border-border px-5 py-3 text-xs text-muted-foreground"><span>{{ entries.length }} affichée(s) sur ce site</span><span>{{ total }} résultat(s) sur les sites joignables</span></div>
            </div>
        </section>

        <div v-if="archiving" class="fixed inset-0 z-[1000] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true"><form class="w-full max-w-md rounded-lg border border-border bg-card p-5 shadow-xl" @submit.prevent="submitArchive"><div class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-red-50 text-red-600 dark:bg-red-950/30"><Archive class="h-4.5 w-4.5" /></span><div><h2 class="text-base font-bold text-foreground">Archiver cette adresse ?</h2><p class="mt-1 text-sm text-muted-foreground">{{ archiving.label }} ne sera plus proposée dans les nouveaux dossiers. Les patients existants restent inchangés.</p></div></div><label class="mb-1.5 mt-5 block text-sm font-medium text-foreground">Motif <span class="text-red-500">*</span></label><textarea v-model="archiveForm.reason" rows="3" class="w-full rounded border border-border bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-primary" placeholder="Pourquoi cette adresse doit-elle être archivée ?"></textarea><p v-if="archiveForm.errors.reason" class="mt-1 text-xs text-red-600">{{ archiveForm.errors.reason }}</p><div class="mt-5 flex justify-end gap-3"><Button size="rg" variant="white-outline" type="button" @click="archiving = null">Annuler</Button><Button size="rg" variant="danger" :disabled="archiveForm.processing"><Archive class="h-4.5 w-4.5" />Archiver</Button></div></form></div>
        <div v-if="bulkAction" class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true" @click.self="closeBulkAction"><form class="w-full max-w-md rounded-lg border border-border bg-card p-5 shadow-xl" @submit.prevent="submitBulkAction"><div class="flex items-start gap-3"><span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded', bulkAction.mode === 'ARCHIVE' ? 'bg-red-50 text-red-600 dark:bg-red-950/30' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30']"><component class="h-4.5 w-4.5" :is="bulkAction.mode === 'ARCHIVE' ? Archive : RotateCcw" /></span><div><h2 class="text-base font-bold text-foreground">{{ bulkAction.mode === 'ARCHIVE' ? 'Archiver' : 'Restaurer' }} {{ bulkAction.count }} adresse(s) ?</h2><p class="mt-1 text-sm leading-5 text-muted-foreground">L’opération est atomique : si une adresse n’est plus compatible, aucune adresse ne sera modifiée.</p></div></div><template v-if="bulkAction.mode === 'ARCHIVE'"><label class="mb-1.5 mt-5 block text-sm font-medium text-foreground">Motif commun <span class="text-red-500">*</span></label><textarea v-model="bulkForm.reason" rows="3" class="w-full rounded border border-border bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-primary" placeholder="Précisez la décision d’archivage"></textarea></template><p v-for="error in Object.values(bulkForm.errors)" :key="error" class="mt-2 text-xs text-red-600">{{ error }}</p><div class="mt-5 flex justify-end gap-3"><Button size="rg" variant="white-outline" type="button" @click="closeBulkAction">Annuler</Button><Button size="rg" :variant="bulkAction.mode === 'ARCHIVE' ? 'danger' : 'primary'" :disabled="bulkForm.processing"><component :is="bulkAction.mode === 'ARCHIVE' ? Archive : RotateCcw" />Confirmer</Button></div></form></div>
    </div>
</template>
