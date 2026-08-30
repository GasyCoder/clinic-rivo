<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import DispenseDeliveryWorkspace from '@/Pages/Pharmacy/Partials/DispenseDeliveryWorkspace.vue';
import DispenseQueue from '@/Pages/Pharmacy/Partials/DispenseQueue.vue';
import PharmacyWorkspaceNav from '@/Pages/Pharmacy/Partials/PharmacyWorkspaceNav.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    capabilities: { type: Object, required: true },
    stock: { type: Object, required: true },
    queue: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    adjustmentTypes: { type: Array, default: () => [] },
    medicineForms: { type: Array, default: () => [] },
});

const page = usePage();
const requestedTab = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
const availableTabs = {
    dispenses: props.capabilities.can_view_prescriptions,
    stock: props.capabilities.can_view_stock,
    setup: props.capabilities.can_view_categories || props.capabilities.can_view_suppliers,
};
const defaultTab = ['dispenses', 'stock', 'setup'].find((tab) => availableTabs[tab]) ?? 'dispenses';
const activeTab = ref(availableTabs[requestedTab] ? requestedTab : defaultTab);
const search = ref('');
const stockStatus = ref('ALL');
const expandedMedicines = ref(new Set());
const showEntryForm = ref(false);
const showAdjustmentForm = ref(false);
const deliveryTarget = ref(null);

const entryForm = useForm({
    medicine_uuid: '',
    operation: 'ENTREE',
    lot_number: '',
    received_at: '',
    expires_at: '',
    quantity: 1,
    supplier_uuid: '',
    unit_purchase_price: '',
    origin: '',
    destination: `Stock Pharmacie — ${page.props.site.name || page.props.site.code}`,
    reason: '',
});

const adjustmentForm = useForm({
    lot_uuid: '',
    type: 'INVENTORY',
    quantity: '',
    counted_quantity: '',
    reason: '',
});
const deliveryForm = useForm({ lines: [], notes: '' });
const categoryForm = useForm({ code: '', name: '', description: '' });
const supplierForm = useForm({ code: '', name: '', contact_name: '', phone: '', email: '', address: '' });
const medicineForm = useForm({
    code: '', name: '', generic_name: '', form: '', strength: '', unit: '', manufacturer: '', barcode: '',
    medicine_category_uuid: '', supplier_uuids: [], minimum_stock: 0, prescription_required: true,
    sale_price: '', tariff_reason: '', description: '',
});
const importForm = useForm({ file: null });

const medicines = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return (props.stock.medicines ?? []).filter((medicine) => {
        const matchesSearch = !needle || [medicine.name, medicine.generic_name, medicine.code, medicine.form_label]
            .filter(Boolean)
            .some((value) => value.toLocaleLowerCase().includes(needle));

        return matchesSearch && (stockStatus.value === 'ALL' || medicine.status === stockStatus.value);
    });
});

const allLots = computed(() => (props.stock.medicines ?? []).flatMap((medicine) => medicine.lots.map((lot) => ({
    ...lot,
    medicine_name: medicine.name,
    unit: medicine.unit,
}))));

const selectedMedicine = computed(() => props.stock.medicines?.find(
    (medicine) => medicine.uuid === entryForm.medicine_uuid,
));

const statusLabel = (value) => ({
    AVAILABLE: 'Disponible',
    OUT_OF_STOCK: 'Rupture',
    EXPIRING_SOON: 'Péremption proche',
    EXPIRED: 'Périmé',
    UNAVAILABLE: 'Indisponible',
    INACTIVE: 'Inactif',
    AWAITING_INVOICE: 'Facturation à préparer',
    AWAITING_PAYMENT: 'En attente de règlement',
    READY: 'Prête à délivrer',
    PARTIALLY_DISPENSED: 'Délivrée partiellement',
    DISPENSED: 'Délivrée',
}[value] ?? value);

const statusClass = (value) => ({
    AVAILABLE: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    OUT_OF_STOCK: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
    EXPIRING_SOON: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    EXPIRED: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
    UNAVAILABLE: 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400',
    INACTIVE: 'border-gray-200 bg-gray-50 text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-slate-400',
    AWAITING_INVOICE: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-300',
    AWAITING_PAYMENT: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300',
    READY: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300',
    PARTIALLY_DISPENSED: 'border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-900 dark:bg-primary-950/30 dark:text-primary-300',
}[value]);

const formatDate = (value, withTime = false) => {
    if (!value) return '—';
    const date = new Date(withTime ? value : `${value}T00:00:00`);
    return new Intl.DateTimeFormat('fr-FR', withTime
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : { dateStyle: 'medium' }).format(date);
};

const formatNumber = (value) => new Intl.NumberFormat('fr-FR').format(value ?? 0);
const toggleInSet = (target, key) => {
    const next = new Set(target.value);
    next.has(key) ? next.delete(key) : next.add(key);
    target.value = next;
};

const submitEntry = () => entryForm.post('/pharmacy/stock/entries', {
    preserveScroll: true,
    onSuccess: () => {
        entryForm.reset();
        showEntryForm.value = false;
    },
});
const submitAdjustment = () => adjustmentForm.post('/pharmacy/stock/adjustments', {
    preserveScroll: true,
    onSuccess: () => {
        adjustmentForm.reset();
        showAdjustmentForm.value = false;
    },
});
const prepareInvoice = (dispense) => router.post(`/pharmacy/dispenses/${dispense.uuid}/invoice`, {}, {
    preserveScroll: true,
});
const openDelivery = (dispense) => {
    deliveryTarget.value = dispense;
    deliveryForm.clearErrors();
    deliveryForm.lines = dispense.lines
        .filter((line) => line.remaining_quantity > 0)
        .map((line) => ({ uuid: line.uuid, quantity: line.remaining_quantity }));
    deliveryForm.notes = '';
};
const submitDelivery = () => deliveryForm
    .transform((data) => ({
        ...data,
        lines: data.lines
            .filter((line) => Number(line.quantity) > 0)
            .map((line) => ({ uuid: line.uuid, quantity: Number(line.quantity) })),
    }))
    .post(`/pharmacy/dispenses/${deliveryTarget.value.uuid}/deliveries`, {
        preserveScroll: true,
        onSuccess: () => { deliveryTarget.value = null; },
    });
const submitCategory = () => categoryForm.post('/pharmacy/setup/categories', { preserveScroll: true, onSuccess: () => categoryForm.reset() });
const submitSupplier = () => supplierForm.post('/pharmacy/setup/suppliers', { preserveScroll: true, onSuccess: () => supplierForm.reset() });
const submitMedicine = () => medicineForm.post('/pharmacy/setup/medicines', { preserveScroll: true, onSuccess: () => medicineForm.reset() });
const submitCatalogImport = () => importForm.post('/pharmacy/setup/medicines/import', {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => importForm.reset(),
});

const focusInvalidField = (key) => document.querySelector(`[name="${CSS.escape(key)}"]`)?.focus();
</script>

<template>
    <Head title="Pharmacie" />

    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-600 dark:bg-primary-950/30 dark:text-primary-300"><Icon class="text-xl" name="capsule" /></span>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Espace opérationnel</p>
                    <h1 class="mt-0.5 font-heading text-2xl font-bold text-slate-700 dark:text-white">Pharmacie</h1>
                    <p class="mt-1 text-sm text-slate-500">Stock physique, réservations, lots, péremptions et ordonnances à préparer.</p>
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <div class="inline-flex items-center gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-xs text-slate-500 dark:border-gray-900 dark:bg-gray-950"><Icon class="text-base text-emerald-500" name="shield-check" /><span>Aucune caisse · aucun encaissement</span></div>
                <Button v-if="capabilities.can_create_counter_sale" :as="Link" href="/pharmacy/counter-sales/create" size="rg" variant="secondary"><Icon name="shopping-cart" /><span class="ms-2">Nouveau client externe</span></Button>
                <Button v-if="capabilities.can_adjust_stock" size="rg" variant="white-outline" type="button" @click="showAdjustmentForm = !showAdjustmentForm"><Icon name="edit" /><span class="ms-2">Ajustement</span></Button>
                <Button v-if="capabilities.can_record_entry" size="rg" type="button" @click="showEntryForm = !showEntryForm"><Icon :name="showEntryForm ? 'cross' : 'plus'" /><span class="ms-2">{{ showEntryForm ? 'Fermer' : 'Nouvelle entrée' }}</span></Button>
            </div>
        </header>

        <PharmacyWorkspaceNav
            :capabilities="capabilities"
            :active="activeTab"
            :dispense-count="queue.summary.dispenses ?? 0"
            @select="activeTab = $event; search = ''"
        />

        <section v-if="activeTab === 'stock' && alerts.length && capabilities.can_view_alerts" class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20">
            <div class="flex items-start gap-3"><Icon class="mt-0.5 text-lg text-amber-600" name="alert-triangle" /><div><h2 class="text-sm font-bold text-amber-900 dark:text-amber-100">Réapprovisionnement requis</h2><div class="mt-2 flex flex-wrap gap-2"><span v-for="alert in alerts" :key="alert.uuid" class="rounded border border-amber-200 bg-white px-2.5 py-1.5 text-xs text-amber-800 dark:border-amber-900 dark:bg-gray-950 dark:text-amber-200"><strong>{{ alert.medicine_name }}</strong> · {{ alert.available_quantity }} disponible(s) / seuil {{ alert.threshold }}</span></div></div></div>
        </section>

        <section v-if="activeTab === 'stock' && capabilities.can_view_stock" class="grid overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950 sm:grid-cols-2 xl:grid-cols-5">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Médicaments</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ formatNumber(stock.summary.medicines) }}</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 xl:border-b-0 xl:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Stock physique</p><p class="mt-1 text-xl font-bold text-slate-700 dark:text-white">{{ formatNumber(stock.summary.quantity_on_hand) }}</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 sm:border-e xl:border-b-0"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Réservé</p><p class="mt-1 text-xl font-bold text-primary-600">{{ formatNumber(stock.summary.reserved_quantity) }}</p></div>
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-900 xl:border-b-0 xl:border-e"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Disponible</p><p class="mt-1 text-xl font-bold text-emerald-600">{{ formatNumber(stock.summary.available_quantity) }}</p></div>
            <div class="px-5 py-4"><p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Alertes</p><p :class="['mt-1 text-xl font-bold', stock.summary.out_of_stock || stock.summary.expiring_soon || stock.summary.expired_lots ? 'text-amber-600' : 'text-slate-700 dark:text-white']">{{ formatNumber((stock.summary.out_of_stock ?? 0) + (stock.summary.expiring_soon ?? 0) + (stock.summary.expired_lots ?? 0)) }}</p></div>
        </section>

        <form v-if="showAdjustmentForm && capabilities.can_adjust_stock" class="rounded-lg border border-amber-200 bg-white p-5 shadow-sm dark:border-amber-900 dark:bg-gray-950" @submit.prevent="submitAdjustment">
            <div class="mb-4"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Ajustement de stock</h2><p class="mt-1 text-xs text-slate-500">Péremption, casse/perte ou inventaire physique. Le mouvement est immuable et audité.</p></div>
            <ValidationErrorSummary class="mb-4" :errors="adjustmentForm.errors" @select="focusInvalidField" />
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="block xl:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Lot <span class="text-red-500">*</span></span><select v-model="adjustmentForm.lot_uuid" name="lot_uuid" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" required><option value="">Sélectionner</option><option v-for="lot in allLots" :key="lot.uuid" :value="lot.uuid">{{ lot.medicine_name }} · lot {{ lot.lot_number }} · {{ lot.quantity_on_hand }} {{ lot.unit }}</option></select></label>
                <label><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Type <span class="text-red-500">*</span></span><select v-model="adjustmentForm.type" name="type" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option v-for="type in adjustmentTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></label>
                <label v-if="adjustmentForm.type === 'INVENTORY'"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Quantité comptée <span class="text-red-500">*</span></span><input v-model="adjustmentForm.counted_quantity" name="counted_quantity" type="number" min="0" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" required></label>
                <label v-else><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Quantité à retirer <span class="text-red-500">*</span></span><input v-model="adjustmentForm.quantity" name="quantity" type="number" min="1" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" required></label>
                <label class="block md:col-span-2 xl:col-span-4"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Justification vérifiable <span class="text-red-500">*</span></span><textarea v-model="adjustmentForm.reason" name="reason" rows="2" maxlength="2000" class="w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" required /></label>
            </div>
            <div class="mt-5 flex justify-end gap-2"><Button variant="white-outline" size="rg" type="button" @click="showAdjustmentForm = false">Annuler</Button><Button size="rg" type="submit" :disabled="adjustmentForm.processing">Enregistrer l’ajustement</Button></div>
        </form>

        <form v-if="showEntryForm" class="rounded-lg border border-primary-200 bg-white p-5 shadow-sm dark:border-primary-900 dark:bg-gray-950" @submit.prevent="submitEntry">
            <div class="mb-5 flex items-start justify-between gap-4">
                <div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Enregistrer une entrée de stock</h2><p class="mt-1 text-xs leading-5 text-slate-500">Chaque quantité crée un mouvement immuable avec lot, auteur, origine, destination et motif.</p></div>
                <span class="hidden rounded bg-gray-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-500 dark:bg-gray-900 sm:inline-flex">Transaction auditée</span>
            </div>

            <ValidationErrorSummary class="mb-4" :errors="entryForm.errors" @select="focusInvalidField" />

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="block xl:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Médicament <span class="text-red-500">*</span></span><select v-model="entryForm.medicine_uuid" name="medicine_uuid" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="">Sélectionner un médicament actif</option><option v-for="medicine in stock.medicines" :key="medicine.uuid" :value="medicine.uuid">{{ medicine.name }} · {{ medicine.code }}</option></select></label>
                <label class="block"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Opération <span class="text-red-500">*</span></span><select v-model="entryForm.operation" name="operation" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="ENTREE">ENTREE — ajout de quantité</option><option v-if="capabilities.can_create_lot" value="STOCK_INITIAL">STOCK_INITIAL — lot nouveau</option></select></label>
                <label class="block"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Quantité <span class="text-red-500">*</span></span><div class="relative"><input v-model="entryForm.quantity" name="quantity" type="number" min="1" step="1" class="h-10 w-full rounded border border-gray-200 bg-white px-3 pe-20 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-slate-400">{{ selectedMedicine?.unit ?? 'unités' }}</span></div></label>
                <label v-if="capabilities.can_view_suppliers" class="block xl:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Fournisseur</span><select v-model="entryForm.supplier_uuid" name="supplier_uuid" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="">Non renseigné</option><option v-for="supplier in suppliers" :key="supplier.uuid" :value="supplier.uuid">{{ supplier.name }} · {{ supplier.code }}</option></select></label>
                <label v-if="capabilities.can_record_cost" class="block xl:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Prix d’achat unitaire (MGA)</span><input v-model="entryForm.unit_purchase_price" name="unit_purchase_price" type="number" min="0" step="0.01" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></label>
                <label class="block xl:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Numéro de lot <span class="text-red-500">*</span></span><input v-model="entryForm.lot_number" name="lot_number" type="text" maxlength="100" :list="selectedMedicine ? 'existing-pharmacy-lots' : undefined" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Saisir ou choisir un lot existant"><datalist id="existing-pharmacy-lots"><option v-for="lot in selectedMedicine?.lots ?? []" :key="lot.uuid" :value="lot.lot_number" /></datalist></label>
                <label class="block"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Date de réception</span><input v-model="entryForm.received_at" name="received_at" type="date" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></label>
                <label class="block"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Péremption <span class="text-red-500">*</span></span><input v-model="entryForm.expires_at" name="expires_at" type="date" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></label>
                <label class="block xl:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Origine <span class="text-red-500">*</span></span><input v-model="entryForm.origin" name="origin" type="text" maxlength="150" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Source réelle du stock"></label>
                <label class="block xl:col-span-2"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Destination <span class="text-red-500">*</span></span><input v-model="entryForm.destination" name="destination" type="text" maxlength="150" class="h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white"></label>
                <label class="block md:col-span-2 xl:col-span-4"><span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-white">Motif <span class="text-red-500">*</span></span><textarea v-model="entryForm.reason" name="reason" rows="2" maxlength="2000" class="w-full rounded border border-gray-200 bg-white px-3 py-2 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Référence ou justification vérifiable de l’entrée" /></label>
            </div>
            <div class="mt-5 flex flex-col-reverse gap-2 border-t border-gray-100 pt-4 dark:border-gray-900 sm:flex-row sm:justify-end"><Button variant="white-outline" size="rg" type="button" @click="showEntryForm = false">Annuler</Button><Button size="rg" :disabled="entryForm.processing"><Icon name="save" /><span class="ms-2">{{ entryForm.processing ? 'Enregistrement…' : 'Enregistrer l’entrée' }}</span></Button></div>
        </form>

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <div v-if="activeTab === 'stock'" class="flex flex-col gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-900 sm:flex-row sm:justify-end">
                    <label class="relative block sm:w-72"><Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" /><input v-model="search" type="search" class="h-9 w-full rounded border border-gray-200 bg-white ps-10 pe-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white" :placeholder="activeTab === 'stock' ? 'Médicament, code, forme…' : 'Patient, passage, médicament…'"></label>
                    <select v-if="activeTab === 'stock'" v-model="stockStatus" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="ALL">Tous les états</option><option value="AVAILABLE">Disponibles</option><option value="OUT_OF_STOCK">Ruptures</option><option v-if="capabilities.can_view_expiration" value="EXPIRING_SOON">Péremption proche</option><option value="INACTIVE">Inactifs</option></select>
            </div>

            <div v-if="activeTab === 'stock' && capabilities.can_view_stock" class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-start text-sm">
                    <thead class="bg-gray-50 text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:bg-gray-1000"><tr><th class="px-5 py-3 text-start">Médicament</th><th class="px-4 py-3 text-start">Forme</th><th class="px-4 py-3 text-end">Physique</th><th class="px-4 py-3 text-end">Réservé</th><th class="px-4 py-3 text-end">Disponible</th><th v-if="capabilities.can_view_expiration" class="px-4 py-3 text-start">Prochaine péremption</th><th class="px-4 py-3 text-start">État</th><th v-if="capabilities.can_view_lots" class="px-5 py-3 text-end">Lots</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-900">
                        <template v-for="medicine in medicines" :key="medicine.uuid">
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-900/30"><td class="px-5 py-3"><p class="font-bold text-slate-700 dark:text-white">{{ medicine.name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ medicine.code }}<span v-if="medicine.generic_name"> · {{ medicine.generic_name }}</span><span v-if="medicine.strength"> · {{ medicine.strength }}</span></p></td><td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ medicine.form_label }}</td><td class="px-4 py-3 text-end font-medium text-slate-600 dark:text-slate-300">{{ formatNumber(medicine.quantity_on_hand) }} {{ medicine.unit }}</td><td class="px-4 py-3 text-end text-primary-600">{{ formatNumber(medicine.reserved_quantity) }}</td><td class="px-4 py-3 text-end font-bold text-slate-700 dark:text-white">{{ formatNumber(medicine.available_quantity) }}</td><td v-if="capabilities.can_view_expiration" class="px-4 py-3 text-slate-500">{{ formatDate(medicine.nearest_expiration) }}</td><td class="px-4 py-3"><span :class="['inline-flex rounded border px-2 py-1 text-[11px] font-bold', statusClass(medicine.status)]">{{ statusLabel(medicine.status) }}</span></td><td v-if="capabilities.can_view_lots" class="px-5 py-3 text-end"><button type="button" class="inline-flex h-8 items-center gap-1.5 rounded border border-gray-200 px-2.5 text-xs font-bold text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:hover:text-white" @click="toggleInSet(expandedMedicines, medicine.uuid)"><Icon :name="expandedMedicines.has(medicine.uuid) ? 'chevron-down' : 'chevron-right'" />{{ medicine.lots.length }}</button></td></tr>
                            <tr v-if="capabilities.can_view_lots && expandedMedicines.has(medicine.uuid)" class="bg-gray-50/70 dark:bg-gray-1000/40"><td colspan="8" class="px-5 py-4"><div class="overflow-x-auto rounded border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950"><table class="w-full min-w-[680px] text-xs"><thead class="bg-gray-50 text-[10px] font-medium uppercase tracking-wide text-slate-400 dark:bg-gray-900"><tr><th class="px-4 py-2 text-start">Lot</th><th v-if="capabilities.can_view_expiration" class="px-4 py-2 text-start">Expiration</th><th class="px-4 py-2 text-end">Physique</th><th class="px-4 py-2 text-end">Réservé</th><th class="px-4 py-2 text-end">Disponible</th><th class="px-4 py-2 text-start">État</th></tr></thead><tbody><tr v-for="lot in medicine.lots" :key="lot.uuid" class="border-t border-gray-100 dark:border-gray-900"><td class="px-4 py-2.5 font-bold text-slate-700 dark:text-white">{{ lot.lot_number }}</td><td v-if="capabilities.can_view_expiration" class="px-4 py-2.5 text-slate-500">{{ formatDate(lot.expires_at) }}</td><td class="px-4 py-2.5 text-end">{{ formatNumber(lot.quantity_on_hand) }}</td><td class="px-4 py-2.5 text-end text-primary-600">{{ formatNumber(lot.reserved_quantity) }}</td><td class="px-4 py-2.5 text-end font-bold">{{ formatNumber(lot.available_quantity) }}</td><td class="px-4 py-2.5"><span :class="['inline-flex rounded border px-2 py-1 text-[10px] font-bold', statusClass(lot.status)]">{{ statusLabel(lot.status) }}</span></td></tr></tbody></table><p v-if="!medicine.lots.length" class="px-4 py-4 text-xs text-slate-400">Aucun lot enregistré.</p></div></td></tr>
                        </template>
                        <tr v-if="!medicines.length"><td colspan="8" class="px-5 py-12 text-center"><Icon class="text-2xl text-slate-300" name="package" /><p class="mt-2 text-sm font-bold text-slate-600 dark:text-slate-300">Aucun médicament trouvé</p><p class="mt-1 text-xs text-slate-400">Modifiez la recherche ou le filtre d’état.</p></td></tr>
                    </tbody>
                </table>
            </div>

            <DispenseQueue
                v-else-if="activeTab === 'dispenses' && capabilities.can_view_prescriptions"
                :dispenses="queue.dispenses ?? []"
                :summary="queue.summary"
                :capabilities="capabilities"
                @prepare-invoice="prepareInvoice"
                @deliver="openDelivery"
            />

            <div v-else-if="activeTab === 'setup'" class="space-y-5 p-5">
                <div class="grid gap-4 lg:grid-cols-2">
                    <section class="rounded border border-gray-200 p-4 dark:border-gray-800"><div class="flex items-center justify-between"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Catégories thérapeutiques</h2><span class="rounded bg-gray-100 px-2 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900">{{ categories.length }}</span></div><div class="mt-3 flex flex-wrap gap-2"><span v-for="category in categories" :key="category.uuid" class="rounded border border-gray-200 px-2.5 py-1.5 text-xs text-slate-600 dark:border-gray-800 dark:text-slate-300"><strong>{{ category.code }}</strong> · {{ category.name }}</span><span v-if="!categories.length" class="text-xs text-slate-400">Aucune catégorie configurée.</span></div><form v-if="capabilities.can_create_category" class="mt-4 grid gap-2 border-t border-gray-100 pt-4 dark:border-gray-900 sm:grid-cols-[140px_minmax(0,1fr)_auto]" @submit.prevent="submitCategory"><input v-model="categoryForm.code" name="code" maxlength="60" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm uppercase dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Code" required><input v-model="categoryForm.name" name="name" maxlength="255" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Nom de la catégorie" required><Button size="sm" type="submit" :disabled="categoryForm.processing">Créer</Button></form></section>
                    <section class="rounded border border-gray-200 p-4 dark:border-gray-800"><div class="flex items-center justify-between"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Fournisseurs</h2><span class="rounded bg-gray-100 px-2 py-1 text-xs font-bold text-slate-500 dark:bg-gray-900">{{ suppliers.length }}</span></div><div class="mt-3 space-y-2"><div v-for="supplier in suppliers" :key="supplier.uuid" class="flex items-center justify-between rounded border border-gray-100 px-3 py-2 text-xs dark:border-gray-900"><span><strong class="text-slate-700 dark:text-white">{{ supplier.name }}</strong> · {{ supplier.code }}</span><span class="text-slate-400">{{ supplier.phone ?? supplier.contact_name ?? '—' }}</span></div><span v-if="!suppliers.length" class="text-xs text-slate-400">Aucun fournisseur configuré.</span></div><form v-if="capabilities.can_create_supplier" class="mt-4 grid gap-2 border-t border-gray-100 pt-4 dark:border-gray-900 sm:grid-cols-[140px_minmax(0,1fr)_auto]" @submit.prevent="submitSupplier"><input v-model="supplierForm.code" name="code" maxlength="60" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm uppercase dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Code" required><input v-model="supplierForm.name" name="name" maxlength="255" class="h-9 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Nom du fournisseur" required><Button size="sm" type="submit" :disabled="supplierForm.processing">Créer</Button></form></section>
                </div>

                <section v-if="capabilities.can_create_medicine" class="rounded border border-primary-200 bg-primary-50/20 p-5 dark:border-primary-900 dark:bg-primary-950/10"><div class="mb-4"><h2 class="text-sm font-bold text-slate-700 dark:text-white">Créer un médicament</h2><p class="mt-1 text-xs text-slate-500">Crée simultanément la fiche produit, l’élément du catalogue et le tarif de vente. Le prix d’achat reste rattaché aux entrées de lots.</p></div><ValidationErrorSummary class="mb-4" :errors="medicineForm.errors" /><form class="grid gap-3 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="submitMedicine"><input v-model="medicineForm.code" name="code" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm uppercase dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Code *" required><input v-model="medicineForm.name" name="name" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Nom commercial *" required><input v-model="medicineForm.generic_name" name="generic_name" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="DCI *" required><select v-model="medicineForm.form" name="form" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" required><option value="">Forme *</option><option v-for="form in medicineForms" :key="form.value" :value="form.value">{{ form.label }}</option></select><input v-model="medicineForm.strength" name="strength" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Dosage *" required><input v-model="medicineForm.unit" name="unit" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Unité *" required><input v-model="medicineForm.manufacturer" name="manufacturer" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Laboratoire / fabricant"><input v-model="medicineForm.barcode" name="barcode" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Code-barres"><select v-model="medicineForm.medicine_category_uuid" name="medicine_category_uuid" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white"><option value="">Sans catégorie</option><option v-for="category in categories" :key="category.uuid" :value="category.uuid">{{ category.name }}</option></select><input v-model="medicineForm.minimum_stock" name="minimum_stock" type="number" min="0" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Stock minimum *" required><input v-model="medicineForm.sale_price" name="sale_price" type="number" min="0.01" step="0.01" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Prix de vente MGA *" required><input v-model="medicineForm.tariff_reason" name="tariff_reason" class="h-10 rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Motif du tarif *" required><label class="flex h-10 items-center gap-2 rounded border border-gray-200 bg-white px-3 text-sm text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300"><input v-model="medicineForm.prescription_required" type="checkbox"> Ordonnance obligatoire</label><div class="flex items-center justify-end xl:col-span-3"><Button type="submit" :disabled="medicineForm.processing">Créer la fiche produit</Button></div></form></section>

                <section v-if="capabilities.can_import_medicines" class="flex flex-col gap-4 rounded border border-gray-200 p-4 dark:border-gray-800 lg:flex-row lg:items-end lg:justify-between"><div><h2 class="text-sm font-bold text-slate-700 dark:text-white">Import en masse Excel / CSV</h2><p class="mt-1 text-xs text-slate-500">Création uniquement. Un code existant ou une ligne invalide annule tout l’import.</p><a href="/pharmacy/setup/medicines/import-template" class="mt-2 inline-flex text-xs font-bold text-primary-600 hover:underline">Télécharger le modèle vide</a></div><form class="flex flex-col gap-2 sm:flex-row sm:items-end" @submit.prevent="submitCatalogImport"><label><span class="mb-1 block text-xs font-bold text-slate-500">Fichier</span><input name="file" type="file" accept=".xlsx,.xls,.csv" class="block text-xs text-slate-500" required @input="importForm.file = $event.target.files[0]"></label><Button size="sm" type="submit" :disabled="importForm.processing">Importer</Button></form></section>

                <div v-if="!capabilities.can_create_medicine && !capabilities.can_import_medicines" class="flex items-start gap-3 rounded border border-sky-200 bg-sky-50 px-4 py-3 text-xs text-sky-800 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-200"><Icon name="shield-check" /><p>Le rôle Pharmacie consulte le paramétrage mais ne modifie pas le catalogue ni les tarifs. Ces opérations restent réservées aux comptes explicitement autorisés par la Super Administration.</p></div>
            </div>
        </section>

        <DispenseDeliveryWorkspace
            :target="deliveryTarget"
            :form="deliveryForm"
            :can-view-lots="capabilities.can_view_lots"
            :can-view-expiration="capabilities.can_view_expiration"
            @close="deliveryTarget = null"
            @submit="submitDelivery"
        />

        <section class="flex items-start gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-xs leading-5 text-sky-800 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-200"><Icon class="mt-0.5 shrink-0 text-base" name="info" /><p><strong>Séparation des responsabilités :</strong> la Pharmacie prépare les factures et délivre après confirmation. L’ouverture de caisse, l’encaissement, les reçus et les annulations financières restent exclusivement dans le module Caisse.</p></section>
    </div>
</template>
