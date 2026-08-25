<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sites: Array,
    summary: Object,
});

const selectedSiteCode = ref(props.sites.find((site) => site.ok)?.site.code ?? props.sites[0]?.site.code);
const search = ref('');
const status = ref('ALL');
const expanded = ref(new Set());
const selectedUuids = ref(new Set());
const showImport = ref(false);
const { can } = usePermissions();
const importForm = useForm({ site_code: selectedSiteCode.value, file: null });

const selectedSite = computed(() => props.sites.find((site) => site.site.code === selectedSiteCode.value));
const medicines = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return (selectedSite.value?.data?.medicines ?? []).filter((medicine) => {
        const matchesSearch = !needle || [medicine.name, medicine.generic_name, medicine.code, medicine.form_label]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase().includes(needle));
        const matchesStatus = status.value === 'ALL' || medicine.status === status.value;

        return matchesSearch && matchesStatus;
    });
});
const selectedMedicines = computed(() => medicines.value.filter((medicine) => selectedUuids.value.has(medicine.uuid)));
const allVisibleSelected = computed(() => medicines.value.length > 0 && medicines.value.every((medicine) => selectedUuids.value.has(medicine.uuid)));

const toggleMedicine = (uuid) => {
    const next = new Set(selectedUuids.value);
    next.has(uuid) ? next.delete(uuid) : next.size < 100 && next.add(uuid);
    selectedUuids.value = next;
};

const toggleAllVisible = () => {
    const next = new Set(selectedUuids.value);
    if (allVisibleSelected.value) medicines.value.forEach((medicine) => next.delete(medicine.uuid));
    else medicines.value.forEach((medicine) => { if (next.size < 100) next.add(medicine.uuid); });
    selectedUuids.value = next;
};

const clearSelection = () => { selectedUuids.value = new Set(); };

const statusLabel = (value) => ({
    AVAILABLE: 'Disponible',
    OUT_OF_STOCK: 'Rupture',
    EXPIRING_SOON: 'Péremption proche',
    EXPIRED: 'Périmé',
    INACTIVE: 'Inactif',
}[value] ?? value);

const statusClass = (value) => ({
    AVAILABLE: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    OUT_OF_STOCK: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
    EXPIRING_SOON: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    EXPIRED: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
    INACTIVE: 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400',
}[value]);

const toggleLots = (uuid) => {
    const next = new Set(expanded.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    expanded.value = next;
};

const formatDate = (value) => value ? new Intl.DateTimeFormat('fr-FR').format(new Date(`${value}T00:00:00`)) : '—';
const exportUrl = computed(() => `/super-admin/stock/export?site_code=${encodeURIComponent(selectedSiteCode.value)}`);
const selectedExportUrl = computed(() => {
    const params = new URLSearchParams({ site_code: selectedSiteCode.value });
    selectedMedicines.value.forEach((medicine) => params.append('medicine_uuids[]', medicine.uuid));
    return `/super-admin/stock/export?${params.toString()}`;
});
const selectSite = (code) => {
    selectedSiteCode.value = code;
    importForm.site_code = code;
    showImport.value = false;
    expanded.value = new Set();
    clearSelection();
};
const submitImport = () => importForm.post('/super-admin/stock/import', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { importForm.reset('file'); showImport.value = false; },
});
</script>

<template>
    <Head title="Stock médicaments" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-400"><Icon class="text-xl" name="capsule" /></span>
                <div><p class="text-xs font-medium uppercase tracking-wide text-slate-400">Super Administration</p><h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Stock médicaments</h1><p class="mt-1 text-sm text-slate-500">Disponibilités, réservations, lots et péremptions consolidés par site.</p></div>
            </div>
            <div class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
                <div class="inline-flex items-start gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-xs leading-5 text-slate-500 dark:border-gray-900 dark:bg-gray-950"><Icon class="mt-0.5 text-base" name="info" /><span>Chaque import crée des mouvements audités dans le site choisi. Aucun ajustement silencieux n’est accepté.</span></div>
                <Button v-if="can('stock.import')" size="rg" variant="white-outline" type="button" @click="showImport = !showImport"><Icon class="text-lg" :name="showImport ? 'cross' : 'upload'" /><span class="ms-2">{{ showImport ? 'Fermer' : 'Importer Excel' }}</span></Button>
                <Button v-if="can('stock.export') && selectedSite?.ok" as="a" :href="exportUrl" size="rg" variant="white-outline"><Icon class="text-lg" name="download" /><span class="ms-2">Exporter Excel</span></Button>
                <Button v-else-if="can('stock.export')" size="rg" variant="white-outline" type="button" disabled title="Configurez et connectez l’API du site pour exporter"><Icon class="text-lg" name="download" /><span class="ms-2">Exporter Excel</span></Button>
            </div>
        </header>

        <form v-if="showImport" class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-900 dark:bg-gray-950" @submit.prevent="submitImport">
            <p v-if="!selectedSite?.ok" class="mb-4 flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><Icon name="info" />Vous pouvez préparer le fichier, mais l’import sera disponible après la configuration de l’API du site.</p>
            <div class="mb-4 flex items-start gap-3 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><Icon class="mt-0.5 shrink-0 text-base" name="info" /><p><strong>STOCK_INITIAL</strong> crée uniquement un nouveau lot. <strong>ENTREE</strong> ajoute la quantité à un lot existant ou nouveau. Toute opération est historisée et ne peut pas être supprimée.</p></div>
            <div class="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-end">
                <div><label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Site destinataire <span class="text-red-500">*</span></label><select v-model="importForm.site_code" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option v-for="site in sites" :key="site.site.code" :value="site.site.code" :disabled="!site.ok">{{ site.site.name }}{{ site.ok ? '' : ' — indisponible' }}</option></select></div>
                <div><div class="mb-1.5 flex items-center justify-between gap-3"><label class="block text-sm font-medium text-slate-700 dark:text-white">Fichier Excel <span class="text-red-500">*</span></label><a href="/super-admin/stock/import-template" class="text-xs font-bold text-primary-600 hover:text-primary-700">Télécharger le modèle Excel</a></div><input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="block h-10 w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm text-slate-600 file:me-3 file:border-0 file:bg-transparent file:text-xs file:font-bold file:text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" @change="importForm.file = $event.target.files[0] ?? null"><p class="mt-1 text-xs text-slate-400">Format .xlsx uniquement · 500 lignes maximum · 5 Mo.</p><p v-for="error in Object.values(importForm.errors)" :key="error" class="mt-1 text-xs text-red-600">{{ error }}</p></div>
                <Button size="rg" :disabled="importForm.processing || !selectedSite?.ok"><Icon class="text-lg" name="upload" /><span class="ms-2">{{ importForm.processing ? 'Importation…' : 'Importer le stock' }}</span></Button>
            </div>
        </form>

        <section class="grid overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 sm:grid-cols-2 xl:grid-cols-5">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Sites connectés</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.online_sites }} / {{ sites.length }}</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 xl:border-b-0 xl:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Médicaments</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.medicines }}</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Disponible</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ summary.available_quantity }}</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 xl:border-b-0 xl:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Ruptures</p><p :class="['mt-1 text-xl font-bold', summary.out_of_stock ? 'text-red-600' : 'text-slate-700 dark:text-white']">{{ summary.out_of_stock }}</p></div>
            <div class="px-5 py-4"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Alertes péremption</p><p :class="['mt-1 text-xl font-bold', summary.expiring_soon + summary.expired_lots ? 'text-amber-600' : 'text-slate-700 dark:text-white']">{{ summary.expiring_soon + summary.expired_lots }}</p></div>
        </section>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-900 xl:flex-row xl:items-center xl:justify-between">
                <div class="inline-flex max-w-full gap-1 overflow-x-auto rounded bg-gray-100 p-1 dark:bg-gray-900">
                    <button v-for="site in sites" :key="site.site.code" type="button" :class="['inline-flex shrink-0 items-center gap-2 rounded px-3 py-2 text-xs font-bold', selectedSiteCode === site.site.code ? 'bg-white text-slate-700 shadow-sm dark:bg-gray-950 dark:text-white' : 'text-slate-500']" @click="selectSite(site.site.code)"><span :class="['h-1.5 w-1.5 rounded-full', site.ok ? 'bg-emerald-500' : site.status === 'OFFLINE' || site.status === 'ERROR' ? 'bg-red-500' : 'bg-slate-300']" />{{ site.site.name }}</button>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <label class="relative block sm:w-72"><Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" /><input v-model="search" type="search" class="h-9 w-full rounded border border-gray-200 bg-white ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Médicament, code, forme…"></label>
                    <select v-model="status" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="ALL">Tous les états</option><option value="AVAILABLE">Disponibles</option><option value="OUT_OF_STOCK">Ruptures</option><option value="EXPIRING_SOON">Péremption proche</option><option value="INACTIVE">Inactifs</option></select>
                </div>
            </div>

            <div v-if="!selectedSite?.ok" class="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center"><span class="flex h-11 w-11 items-center justify-center rounded bg-gray-100 text-slate-400 dark:bg-gray-900"><Icon class="text-xl" name="server" /></span><h2 class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Données indisponibles pour {{ selectedSite?.site.name }}</h2><p class="mt-1 max-w-lg text-xs leading-5 text-slate-500">{{ selectedSite?.message }}</p></div>

            <div v-else>
                <div v-if="selectedMedicines.length" class="flex flex-col gap-3 border-b border-gray-200 bg-primary-50/60 px-5 py-3 dark:border-gray-900 dark:bg-primary-950/10 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><span class="flex h-8 min-w-8 items-center justify-center rounded bg-primary-600 px-2 text-xs font-bold text-white">{{ selectedMedicines.length }}</span><div><p class="text-sm font-bold text-slate-700 dark:text-white">médicament(s) sélectionné(s)</p><p class="text-xs text-slate-500">L’export inclut tous les lots des lignes retenues · 100 maximum</p></div></div><div class="flex items-center gap-2"><Button v-if="can('stock.export')" as="a" :href="selectedExportUrl" size="sm"><Icon name="download" /><span class="ms-2">Exporter la sélection</span></Button><button type="button" class="px-2 py-1 text-xs font-bold text-slate-500 hover:text-slate-700 dark:hover:text-white" @click="clearSelection">Désélectionner</button></div></div>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-start text-sm">
                    <thead class="bg-gray-50 text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:bg-gray-1000"><tr><th class="w-10 px-4 py-3 text-start"><input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="allVisibleSelected" aria-label="Sélectionner tous les médicaments affichés" @change="toggleAllVisible"></th><th class="px-3 py-3 text-start">Médicament</th><th class="px-4 py-3 text-start">Forme</th><th class="px-4 py-3 text-end">Physique</th><th class="px-4 py-3 text-end">Réservé</th><th class="px-4 py-3 text-end">Disponible</th><th class="px-4 py-3 text-start">Prochaine péremption</th><th class="px-4 py-3 text-start">État</th><th class="px-5 py-3 text-end">Lots</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <template v-for="medicine in medicines" :key="medicine.uuid">
                            <tr :class="['hover:bg-gray-50/60 dark:hover:bg-gray-900/30', selectedUuids.has(medicine.uuid) ? 'bg-primary-50/30 dark:bg-primary-950/10' : '']"><td class="px-4 py-3"><input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" :checked="selectedUuids.has(medicine.uuid)" :aria-label="`Sélectionner ${medicine.name}`" @change="toggleMedicine(medicine.uuid)"></td><td class="px-3 py-3"><p class="font-bold text-slate-700 dark:text-white">{{ medicine.name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ medicine.code }}<span v-if="medicine.generic_name"> · {{ medicine.generic_name }}</span><span v-if="medicine.strength"> · {{ medicine.strength }}</span></p></td><td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ medicine.form_label }}</td><td class="px-4 py-3 text-end font-medium text-slate-600 dark:text-slate-300">{{ medicine.quantity_on_hand }} {{ medicine.unit }}</td><td class="px-4 py-3 text-end text-slate-500">{{ medicine.reserved_quantity }}</td><td class="px-4 py-3 text-end font-bold text-slate-700 dark:text-white">{{ medicine.available_quantity }}</td><td class="px-4 py-3 text-slate-500">{{ formatDate(medicine.nearest_expiration) }}</td><td class="px-4 py-3"><span :class="['inline-flex rounded border px-2 py-1 text-[11px] font-bold', statusClass(medicine.status)]">{{ statusLabel(medicine.status) }}</span></td><td class="px-5 py-3 text-end"><button type="button" class="inline-flex h-8 items-center gap-1.5 rounded border border-gray-200 px-2.5 text-xs font-bold text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" @click="toggleLots(medicine.uuid)"><Icon :name="expanded.has(medicine.uuid) ? 'chevron-up' : 'chevron-down'" />{{ medicine.lots.length }}</button></td></tr>
                            <tr v-if="expanded.has(medicine.uuid)" class="bg-gray-50/70 dark:bg-gray-1000/40"><td colspan="9" class="px-5 py-4"><div class="overflow-hidden rounded border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950"><div class="grid grid-cols-[minmax(150px,1fr)_repeat(4,minmax(110px,.6fr))_minmax(130px,.7fr)] border-b border-gray-200 bg-gray-50 px-4 py-2 text-[10px] font-medium uppercase tracking-wide text-slate-400 dark:border-gray-800 dark:bg-gray-900"><span>Lot</span><span>Expiration</span><span class="text-end">Physique</span><span class="text-end">Réservé</span><span class="text-end">Disponible</span><span>État</span></div><div v-for="lot in medicine.lots" :key="lot.uuid" class="grid grid-cols-[minmax(150px,1fr)_repeat(4,minmax(110px,.6fr))_minmax(130px,.7fr)] items-center border-b border-gray-100 px-4 py-2.5 text-xs last:border-0 dark:border-gray-900"><span class="font-bold text-slate-700 dark:text-white">{{ lot.lot_number }}</span><span class="text-slate-500">{{ formatDate(lot.expires_at) }}</span><span class="text-end">{{ lot.quantity_on_hand }}</span><span class="text-end text-slate-500">{{ lot.reserved_quantity }}</span><span class="text-end font-bold">{{ lot.available_quantity }}</span><span><span :class="['inline-flex rounded border px-2 py-1 text-[10px] font-bold', statusClass(lot.status)]">{{ statusLabel(lot.status) }}</span></span></div><p v-if="!medicine.lots.length" class="px-4 py-4 text-xs text-slate-400">Aucun lot enregistré.</p></div></td></tr>
                        </template>
                        <tr v-if="!medicines.length"><td colspan="9" class="px-5 py-12 text-center text-sm text-slate-400">Aucun médicament ne correspond à ces filtres.</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
        </section>
    </div>
</template>
