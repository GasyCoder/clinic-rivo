<script setup>
import { computed, ref } from 'vue';
import { medicineFamily, medicineMatches, medicineSubtitle } from '@/utilities/medicine';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import ExplorerTile from '@/Components/UI/ExplorerTile.vue';
import ExplorerView from '@/Components/UI/ExplorerView.vue';
import { lucideIcon } from '@/lib/icons';
import {
    Activity, Banknote, Boxes, ClipboardList, Download, Folder, ListChecks, Package, Pencil, Pill,
    Plus, QrCode, Search, Tag, Tags, TriangleAlert, Upload,
} from 'lucide-vue-next';
import PageHeader from '@/Components/UI/PageHeader.vue';
import MedicineFamilies from '@/Components/Pharmacy/MedicineFamilies.vue';
import ShadcnButton from '@/Components/Shadcn/Button.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import FormField from '@/Components/Shadcn/FormField.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import { formatDate } from '@/utilities/date';
import { printMedicineLabels } from '@/utilities/medicineLabels';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber, statusTone } from '@/utilities/pharmacyStatus';
import { pharmacyUrl } from '@/utilities/pharmacyUrl';
import SiteOnlyAction from '@/Components/Pharmacy/SiteOnlyAction.vue';

defineOptions({ layout: AppLayout });

/*
 * ADR-098 — « Médicaments & stock »: the clinic's medicines once, with their
 * family, price and delivery rule, and their stock when the account may see
 * it. It replaces the former separate Stock and Médicaments lists.
 */
const props = defineProps({
    capabilities: { type: Object, required: true },
    stock: { type: Object, required: true },
    // ADR-182 — ce qui a été réceptionné et attend d'être rangé.
    awaitingStockCount: { type: Number, default: 0 },
    alerts: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});

const page = usePage();
const showStock = computed(() => props.capabilities.can_view_stock);
const initialStatus = new URLSearchParams(page.url.split('?')[1] ?? '').get('status');
const status = ref(initialStatus ?? 'ALL');
const search = ref('');
const family = ref('');

/*
 * ADR-174 — la Pharmacie fixe elle-même le prix de vente. Le premier se
 * saisit sans motif ; un changement en exige un, l'ancien prix restant dans
 * l'historique.
 */
const pricing = ref(null);
const priceForm = useForm({ sale_price: '', reason: '' });

const openPricing = (medicine) => {
    priceForm.reset();
    priceForm.clearErrors();
    priceForm.sale_price = medicine.sale_price ?? '';
    pricing.value = medicine;
};

const savePrice = () => {
    priceForm.put(pharmacyUrl(`/pharmacy/medicines/${pricing.value.uuid}/sale-price`), {
        preserveScroll: true,
        onSuccess: () => { pricing.value = null; },
    });
};

const medicines = computed(() => props.stock.medicines ?? []);
/*
 * ADR-176 — un produit commandé n'a pas encore rejoint la pharmacie : il entre
 * au catalogue parce qu'une ligne de commande doit le désigner (ADR-098), mais
 * il n'a ni lot, ni stock, ni prix. Le compter « En rupture » ferait lire
 * « on le tient d'habitude et il n'y en a plus ». Il vit donc dans son propre
 * onglet, hors de la liste courante, jusqu'à sa première entrée en stock.
 */
const stocked = computed(() => medicines.value.filter((item) => item.status !== 'NEVER_RECEIVED'));
const neverReceived = computed(() => medicines.value.filter((item) => item.status === 'NEVER_RECEIVED'));
const activeCategories = computed(() => props.categories.filter((category) => !category.archived));
const unpriced = computed(() => stocked.value.filter((medicine) => medicine.active && !medicine.sale_price));

const filters = computed(() => [
    { value: 'ALL', label: 'Tous', count: stocked.value.length },
    // Les catégories restent exclusives : leur somme fait « Tous ». Un produit
    // dont un lot périme bientôt est disponible, mais il est compté sous
    // « Péremption proche » — le libellé le dit plutôt que de laisser lire 0.
    { value: 'AVAILABLE', label: 'Disponibles sans alerte', count: stocked.value.filter((item) => item.status === 'AVAILABLE').length },
    { value: 'OUT_OF_STOCK', label: 'En rupture', count: props.stock.summary?.out_of_stock ?? 0 },
    ...(props.capabilities.can_view_expiration
        ? [{ value: 'EXPIRING_SOON', label: 'Péremption proche', count: props.stock.summary?.expiring_soon ?? 0 }]
        : []),
    { value: 'INACTIVE', label: 'Inactifs', count: stocked.value.filter((item) => item.status === 'INACTIVE').length },
    ...(neverReceived.value.length
        ? [{ value: 'NEVER_RECEIVED', label: 'Commandés, jamais reçus', count: neverReceived.value.length }]
        : []),
    // Un produit sans prix de vente ne peut être ni vendu ni délivré : il
    // doit se voir, sinon le comptoir paraît vide sans raison (ADR-098).
    ...(unpriced.value.length ? [{ value: 'NO_SALE_PRICE', label: 'Sans prix de vente', count: unpriced.value.length }] : []),
]);

const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return medicines.value.filter((medicine) => {
        const neverReceivedRow = medicine.status === 'NEVER_RECEIVED';
        const matchesStatus = status.value === 'NO_SALE_PRICE'
            ? (medicine.active && !medicine.sale_price && !neverReceivedRow)
            : status.value === 'NEVER_RECEIVED'
                ? neverReceivedRow
                // Hors de son onglet, un produit jamais reçu ne figure dans
                // aucune liste — pas même « Tous ».
                : (!neverReceivedRow && (!showStock.value || status.value === 'ALL' || medicine.status === status.value));
        const matchesFamily = !family.value || medicine.category?.name === family.value;
        const matchesSearch = medicineMatches(medicine, needle);

        return matchesStatus && matchesFamily && matchesSearch;
    });
});

const selectStatus = (value) => {
    status.value = value;
    router.replace({ url: value === 'ALL' ? pharmacyUrl('/pharmacy/stock') : pharmacyUrl(`/pharmacy/stock?status=${value}`), preserveState: true, preserveScroll: true });
};

const TILE = {
    AVAILABLE: { tone: 'emerald', badge: 'Disponible' },
    OUT_OF_STOCK: { tone: 'rose', badge: 'Rupture' },
    EXPIRING_SOON: { tone: 'amber', badge: 'Péremption' },
    INACTIVE: { tone: 'slate', badge: 'Inactif' },
    // ADR-176 — commandé, pas encore arrivé : ce n'est pas une rupture.
    NEVER_RECEIVED: { tone: 'sky', badge: 'Jamais reçu' },
};
const tile = (medicine) => (showStock.value ? TILE[medicine.status] : null) ?? { tone: medicine.active ? 'primary' : 'slate', badge: null };
const tileHref = (medicine) => (showStock.value ? pharmacyUrl(`/pharmacy/stock/${medicine.uuid}`) : (props.capabilities.can_update_medicine ? pharmacyUrl(`/pharmacy/medicines/${medicine.uuid}/edit`) : null));

// QR labels: tick medicines (or all the visible ones), then print one sheet.
const selected = ref([]);
const allVisibleSelected = computed(() => visible.value.length > 0 && visible.value.every((medicine) => selected.value.includes(medicine.uuid)));
const toggle = (uuid) => {
    selected.value = selected.value.includes(uuid) ? selected.value.filter((item) => item !== uuid) : [...selected.value, uuid];
};
const toggleAllVisible = () => {
    const uuids = visible.value.map((medicine) => medicine.uuid);
    selected.value = allVisibleSelected.value
        ? selected.value.filter((uuid) => !uuids.includes(uuid))
        : [...new Set([...selected.value, ...uuids])];
};
const popupBlocked = ref(false);
const printLabels = async (list) => {
    popupBlocked.value = !(await printMedicineLabels(list, page.props.site?.name));
};
const printSelection = () => printLabels(medicines.value.filter((medicine) => selected.value.includes(medicine.uuid)));

const importForm = useForm({ file: null });
const submitImport = () => importForm.post(pharmacyUrl('/pharmacy/setup/medicines/import'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => importForm.reset(),
});

const summaryCards = computed(() => (showStock.value ? [
    { label: 'Médicaments', value: stocked.value.length, icon: 'capsule', tone: 'bg-primary-50 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300', filter: 'ALL' },
    { label: 'Disponibles sans alerte', value: stocked.value.filter((item) => item.status === 'AVAILABLE').length, icon: 'check-circle', tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300', filter: 'AVAILABLE' },
    { label: 'En rupture', value: props.stock.summary?.out_of_stock ?? 0, icon: 'alert', tone: 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300', filter: 'OUT_OF_STOCK' },
    { label: 'Péremption proche', value: props.stock.summary?.expiring_soon ?? 0, icon: 'clock', tone: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300', filter: 'EXPIRING_SOON' },
    ...(neverReceived.value.length
        ? [{ label: 'Commandés, jamais reçus', value: neverReceived.value.length, icon: 'truck', tone: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300', filter: 'NEVER_RECEIVED' }]
        : []),
] : []));
</script>

<template>
    <Head title="Médicaments & stock" />

    <div class="w-full space-y-5">
        <PageHeader
            eyebrow="Pharmacie"
            title="Médicaments & stock"
            description="Les médicaments de la clinique, leur prix de vente et ce qui est disponible aujourd’hui."
            icon="capsule"
            tone="emerald"
        >
            <template #actions>
                <SiteOnlyAction v-if="capabilities.can_adjust_stock && capabilities.can_view_lots" label="Inventaire" size="rg" variant="white-outline">
                    <Button :as="Link" :href="pharmacyUrl('/pharmacy/stock/inventory')" size="rg" variant="white-outline">
                        <ListChecks class="h-4 w-4" /><span class="ms-2">Inventaire</span>
                    </Button>
                </SiteOnlyAction>
                <SiteOnlyAction v-if="capabilities.can_adjust_stock" label="Corriger" size="rg" variant="white-outline">
                    <Button :as="Link" :href="pharmacyUrl('/pharmacy/stock/adjustments/create')" size="rg" variant="white-outline">
                        <Pencil class="h-4 w-4" /><span class="ms-2">Corriger</span>
                    </Button>
                </SiteOnlyAction>
                <Button v-if="capabilities.can_create_medicine" :as="Link" :href="pharmacyUrl('/pharmacy/medicines/create')" size="rg" variant="white-outline">
                    <Plus class="h-4 w-4" /><span class="ms-2">Nouveau médicament</span>
                </Button>
                <!-- ADR-182 — le stock n'entre que depuis une livraison
                     réceptionnée : le bouton dit ce qui attend d'être rangé. -->
                <SiteOnlyAction v-if="capabilities.can_record_entry" :label="awaitingStockCount ? `Entrée en stock · ${formatNumber(awaitingStockCount)} à ranger` : 'Entrée en stock'" size="rg" variant="default">
                    <Button :as="Link" :href="pharmacyUrl('/pharmacy/stock/entries/create')" size="rg" title="Ranger au stock ce qui a été réceptionné">
                        <Package class="h-4 w-4" /><span class="ms-2">Entrée en stock</span>
                        <span v-if="awaitingStockCount" class="ms-2 rounded-full bg-white/20 px-2 py-0.5 text-xs font-bold tabular-nums">{{ formatNumber(awaitingStockCount) }} à ranger</span>
                    </Button>
                </SiteOnlyAction>
            </template>
        </PageHeader>

        <div v-if="summaryCards.length" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <button
                v-for="card in summaryCards"
                :key="card.label"
                type="button"
                :class="['flex items-center gap-3 rounded-xl border bg-white p-4 text-start shadow-sm transition hover:shadow-md dark:bg-gray-950', status === card.filter ? 'border-primary-400 dark:border-primary-700' : 'border-gray-200 dark:border-gray-900']"
                @click="selectStatus(card.filter)"
            >
                <span :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', card.tone]"><component :is="lucideIcon(card.icon)" class="h-5 w-5" /></span>
                <span><span class="block text-2xl font-bold tabular-nums text-slate-800 dark:text-white">{{ card.value }}</span><span class="text-xs text-slate-500">{{ card.label }}</span></span>
            </button>
        </div>

        <section v-if="unpriced.length" class="flex flex-wrap items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                    {{ unpriced.length }} médicament{{ unpriced.length > 1 ? 's' : '' }} sans prix de vente
                </p>
                <p class="mt-0.5 text-sm text-amber-900/90 dark:text-amber-100/90">
                    Un produit reçu d’un fournisseur entre au catalogue avec son prix d’achat seulement : le prix de vente au patient est une décision distincte.
                    Tant qu’il manque, le produit ne peut être ni vendu à la Réception ni délivré.
                    <template v-if="capabilities.can_set_sale_price">Utilisez le bouton « Prix » de chaque ligne pour le fixer.</template>
                    <template v-else>Ce prix se fixe par un compte Pharmacie ou depuis le portail Super Administration.</template>
                </p>
            </div>
            <Button size="rg" variant="white-outline" type="button" @click="selectStatus('NO_SALE_PRICE')">Voir lesquels</Button>
        </section>

        <section v-if="alerts.length && capabilities.can_view_alerts" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/20">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <p class="text-sm text-amber-900 dark:text-amber-100">
                <strong>{{ alerts.length }} médicament{{ alerts.length > 1 ? 's' : '' }} à recommander :</strong>
                {{ alerts.slice(0, 4).map((alert) => alert.medicine_name).join(', ') }}<span v-if="alerts.length > 4">…</span>
            </p>
        </section>

        <ExplorerView
            storage-key="pharmacy-medicines"
            :count="visible.length"
            count-label="médicament"
            empty-icon="capsule"
            empty-title="Aucun médicament trouvé"
            empty-description="Modifiez la recherche, la famille ou le filtre choisi."
        >
            <template #toolbar>
                <label class="relative block w-full sm:w-72">
                    <span class="sr-only">Rechercher un médicament</span>
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-slate-400" />
                    <input v-model="search" type="search" class="h-9 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm outline-none focus:border-primary-500 focus:bg-white focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Nom, DCI, code ou code-barres…">
                </label>
                <select v-if="activeCategories.length" v-model="family" class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" aria-label="Filtrer par famille">
                    <option value="">Toutes les familles</option>
                    <option v-for="category in activeCategories" :key="category.uuid" :value="category.name">{{ category.name }}</option>
                </select>
            </template>

            <template #above>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-white px-4 py-2.5 dark:border-gray-900 dark:bg-gray-950">
                    <div v-if="showStock" class="flex max-w-full gap-1.5 overflow-x-auto" role="tablist" aria-label="Filtrer par état">
                        <button
                            v-for="filter in filters"
                            :key="filter.value"
                            type="button"
                            role="tab"
                            :aria-selected="status === filter.value"
                            :class="['inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition', status === filter.value ? 'bg-slate-800 text-white dark:bg-white dark:text-slate-800' : 'text-slate-500 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-gray-900']"
                            @click="selectStatus(filter.value)"
                        >
                            {{ filter.label }} <span class="opacity-70">{{ filter.count }}</span>
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-slate-600 dark:text-slate-300">
                            <input type="checkbox" class="h-4 w-4 rounded border-gray-300" :checked="allVisibleSelected" :disabled="!visible.length" @change="toggleAllVisible">
                            Tout sélectionner
                        </label>
                        <button v-if="selected.length" type="button" class="text-xs font-bold text-slate-500 hover:text-slate-700" @click="selected = []">Désélectionner ({{ selected.length }})</button>
                        <Button size="sm" type="button" :disabled="!selected.length" @click="printSelection"><QrCode class="h-4 w-4" /><span class="ms-1.5">Étiquettes QR</span></Button>
                    </div>
                    <p v-if="popupBlocked" class="w-full text-xs text-red-600">Le navigateur a bloqué la fenêtre d’impression : autorisez les fenêtres pour ce site, puis réessayez.</p>
                </div>
            </template>

            <template #grid>
                <ExplorerTile
                    v-for="medicine in visible"
                    :key="medicine.uuid"
                    :href="tileHref(medicine)"
                    icon="capsule"
                    :tone="tile(medicine).tone"
                    :badge="tile(medicine).badge"
                    :title="medicine.name"
                    :subtitle="medicineSubtitle(medicine)"
                    :highlight="showStock ? `${formatNumber(medicine.available_quantity)} ${medicine.unit ?? ''}` : null"
                    :meta="medicine.sale_price ? formatMoney(medicine.sale_price) : 'Prix non défini'"
                    :muted="!medicine.active"
                    selectable
                    :selected="selected.includes(medicine.uuid)"
                    @toggle="toggle(medicine.uuid)"
                >
                    <template #actions>
                        <Button v-if="capabilities.can_update_medicine" :as="Link" :href="pharmacyUrl(`/pharmacy/medicines/${medicine.uuid}/edit`)" size="sm" variant="white-outline" :title="`Modifier ${medicine.name}`"><Pencil class="h-4 w-4" /></Button>
                        <Button size="sm" variant="white-outline" type="button" :title="`Étiquette QR de ${medicine.name}`" @click="printLabels([medicine])"><QrCode class="h-4 w-4" /></Button>
                    </template>
                </ExplorerTile>
            </template>

            <template #list>
                <table class="w-full min-w-[980px] text-sm">
                    <!-- Même en-tête que les autres listes de l'application
                         (Hospitalisation, Maternité) : une icône par colonne,
                         et les tokens sémantiques plutôt qu'une palette en dur
                         (ADR-099). -->
                    <thead>
                        <tr class="border-b border-border bg-muted/40 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            <th scope="col" class="w-10 px-4 py-3"><span class="sr-only">Sélection</span></th>
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Pill class="h-3.5 w-3.5" aria-hidden="true" />Médicament</span></th>
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Tags class="h-3.5 w-3.5" aria-hidden="true" />Famille</span></th>
                            <th scope="col" class="px-4 py-3 text-end"><span class="inline-flex items-center gap-1.5"><Banknote class="h-3.5 w-3.5" aria-hidden="true" />Prix de vente</span></th>
                            <!-- Un nombre et un conditionnement sont deux
                                 informations : « 4 boîte de 100 » se lit mal en
                                 une colonne, et on ne sait plus ce qui est
                                 compté. -->
                            <th v-if="showStock" scope="col" class="px-4 py-3 text-end"><span class="inline-flex items-center gap-1.5"><Package class="h-3.5 w-3.5" aria-hidden="true" />Disponible</span></th>
                            <th v-if="showStock" scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Boxes class="h-3.5 w-3.5" aria-hidden="true" />Présentation</span></th>
                            <th v-if="showStock" scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Activity class="h-3.5 w-3.5" aria-hidden="true" />État</span></th>
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><ClipboardList class="h-3.5 w-3.5" aria-hidden="true" />Délivrance</span></th>
                            <th scope="col" class="px-5 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                        <tr v-for="medicine in visible" :key="medicine.uuid" :class="['transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/40', selected.includes(medicine.uuid) && 'bg-primary-50/40 dark:bg-primary-950/10']">
                            <td class="px-4 py-3.5">
                                <input type="checkbox" class="h-4 w-4 rounded border-gray-300" :checked="selected.includes(medicine.uuid)" :aria-label="`Sélectionner ${medicine.name}`" @change="toggle(medicine.uuid)">
                            </td>
                            <td class="px-4 py-3.5">
                                <p class="font-semibold text-slate-800 dark:text-white">{{ medicine.name }}</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">{{ medicineSubtitle(medicine) }}</p>
                            </td>
                            <td class="px-4 py-3.5 text-slate-600 dark:text-slate-300">{{ medicineFamily(medicine) ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-end tabular-nums text-slate-700 dark:text-white">{{ medicine.sale_price ? formatMoney(medicine.sale_price) : '—' }}</td>
                            <td v-if="showStock" class="px-4 py-3.5 text-end">
                                <p :class="cn('text-base font-bold tabular-nums', medicine.available_quantity > 0 ? 'text-slate-800 dark:text-white' : 'text-slate-400')">{{ formatNumber(medicine.available_quantity) }}</p>
                                <p v-if="medicine.reserved_quantity" class="text-xs text-slate-400">+ {{ formatNumber(medicine.reserved_quantity) }} réservé{{ medicine.reserved_quantity > 1 ? 's' : '' }}</p>
                            </td>
                            <td v-if="showStock" class="px-4 py-3.5 text-slate-600 dark:text-slate-300">{{ medicine.unit ?? '—' }}</td>
                            <td v-if="showStock" class="px-4 py-3.5">
                                <Badge :tone="statusTone(medicine.status)" dot>{{ medicine.status_label }}</Badge>
                                <p v-if="capabilities.can_view_expiration && medicine.nearest_expiration" class="mt-1 text-xs text-slate-400">Péremption : {{ formatDate(medicine.nearest_expiration) }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <Badge v-if="!medicine.active" tone="neutral">Inactif</Badge>
                                <Badge v-else-if="medicine.prescription_required" tone="warning">Sur ordonnance</Badge>
                                <Badge v-else tone="success">Vente libre</Badge>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    <Button v-if="showStock" :as="Link" :href="pharmacyUrl(`/pharmacy/stock/${medicine.uuid}`)" size="sm" variant="white-outline">Lots</Button>
                                    <Button v-if="capabilities.can_set_sale_price && medicine.active" size="sm" :variant="medicine.sale_price ? 'white-outline' : 'primary'" type="button" :title="`Prix de vente de ${medicine.name}`" @click="openPricing(medicine)">Prix</Button>
                                    <Button v-if="capabilities.can_update_medicine" :as="Link" :href="pharmacyUrl(`/pharmacy/medicines/${medicine.uuid}/edit`)" size="sm" variant="white-outline" :title="`Modifier ${medicine.name}`"><Pencil class="h-4 w-4" /></Button>
                                    <Button size="sm" variant="white-outline" type="button" :title="`Étiquette QR de ${medicine.name}`" @click="printLabels([medicine])"><QrCode class="h-4 w-4" /></Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </ExplorerView>

        <div class="grid gap-4 lg:grid-cols-2">
            <section v-if="capabilities.can_view_categories" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="mb-3 flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300"><Folder class="h-4 w-4" /></span>
                    <div>
                        <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Familles de médicaments</h2>
                        <p class="mt-0.5 text-sm text-slate-500">Cliquez sur une famille pour filtrer la liste.</p>
                    </div>
                </div>
                <MedicineFamilies
                    :categories="categories"
                    :can="{ create: capabilities.can_create_category, update: capabilities.can_update_category, delete: capabilities.can_archive_category, restore: capabilities.can_restore_category }"
                    :base-url="pharmacyUrl('/pharmacy/setup/categories')"
                    :selected-name="family"
                    @select="family = $event"
                />
            </section>

            <section v-if="capabilities.can_import_medicines" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300"><Upload class="h-4 w-4" /></span>
                    <div>
                        <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Ajouter plusieurs médicaments d’un coup</h2>
                        <p class="mt-0.5 text-sm text-slate-500">Remplissez le modèle Excel, puis envoyez-le. Si une seule ligne est incorrecte, rien n’est ajouté.</p>
                    </div>
                </div>
                <a :href="pharmacyUrl('/pharmacy/setup/medicines/import-template')" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary-600 hover:underline"><Download class="h-4 w-4" />Télécharger le modèle</a>
                <form class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center" @submit.prevent="submitImport">
                    <input name="file" type="file" accept=".xlsx,.xls,.csv" class="block flex-1 text-sm text-slate-500 file:me-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-semibold dark:file:bg-gray-900" required @input="importForm.file = $event.target.files[0]">
                    <Button size="rg" type="submit" :disabled="importForm.processing || !importForm.file"><Upload class="h-4 w-4" /><span class="ms-2">Envoyer</span></Button>
                </form>
                <p v-if="importForm.errors.file" class="mt-2 text-xs text-red-600">{{ importForm.errors.file }}</p>
            </section>
        </div>

        <Dialog
            :open="pricing !== null"
            :dismissible="false"
            title="Prix de vente"
            :description="pricing ? `${pricing.name} — prix payé par le patient, à la Réception comme à la délivrance.` : ''"
            @update:open="pricing = $event ? pricing : null"
        >
            <div class="space-y-4">
                <FormField label="Prix de vente (MGA)" required :error="priceForm.errors.sale_price">
                    <Input v-model="priceForm.sale_price" type="number" min="1" step="0.01" inputmode="decimal" />
                </FormField>
                <FormField v-if="pricing?.sale_price" label="Motif du changement" required :error="priceForm.errors.reason">
                    <Textarea v-model="priceForm.reason" :rows="2" placeholder="Ex. : hausse du prix fournisseur" />
                </FormField>
                <p v-if="pricing?.sale_price" class="text-xs text-muted-foreground">Prix actuel : {{ formatMoney(pricing.sale_price) }}. Il reste dans l’historique ; les ventes déjà faites ne changent pas.</p>
            </div>
            <template #footer>
                <ShadcnButton type="button" variant="outline" @click="pricing = null">Annuler</ShadcnButton>
                <ShadcnButton type="button" :disabled="priceForm.processing || !priceForm.sale_price" @click="savePrice"><Tag class="h-4 w-4" />Enregistrer le prix</ShadcnButton>
            </template>
        </Dialog>
    </div>
</template>
