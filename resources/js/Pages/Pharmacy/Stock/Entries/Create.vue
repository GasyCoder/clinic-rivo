<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowRight, Building2, CheckCircle2, Inbox, PackageCheck, PackagePlus, Search, Truck,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import StockEntryTable from '@/Components/Pharmacy/StockEntryTable.vue';
import { cn } from '@/lib/cn';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-113 — un seul écran pour faire entrer de la marchandise au stock.
 *
 * Ce qui a été réceptionné arrive déjà rempli : fournisseur, commande, lots,
 * péremptions et quantités viennent de la réception. On relit, on corrige ce
 * qui doit l'être, on valide. Une entrée sans commande (don, stock de départ)
 * utilise le même tableau. Aucune date n'est demandée : le serveur date
 * l'entrée, et le rangement est le stock de la pharmacie du site.
 */
const props = defineProps({
    capabilities: { type: Object, required: true },
    medicines: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
    initialMode: { type: String, default: 'received' },
    initialSupplier: { type: String, default: '' },
    initialOrder: { type: String, default: '' },
});

const page = usePage();
const pendingLines = computed(() => props.pending.reduce((sum, supplier) => sum + supplier.lines_count, 0));
const mode = ref(props.initialMode === 'manual' || !props.pending.length ? (props.pending.length ? props.initialMode : 'manual') : 'received');

// --- Marchandise réceptionnée ------------------------------------------------
const supplierUuid = ref(props.pending.some((supplier) => supplier.uuid === props.initialSupplier)
    ? props.initialSupplier
    : (props.pending[0]?.uuid ?? ''));
const orderUuid = ref(props.initialOrder);
const currentSupplier = computed(() => props.pending.find((supplier) => supplier.uuid === supplierUuid.value) ?? null);

const toRow = (line) => ({
    key: line.uuid,
    uuid: line.uuid,
    order_uuid: line.order_uuid,
    medicine_uuid: line.medicine_uuid,
    name: line.medicine_name,
    code: line.medicine_code,
    unit: line.unit,
    is_new: line.is_new,
    receipt_number: line.receipt_number,
    notes: line.notes,
    lot_number: line.lot_number,
    expires_at: line.expires_at,
    quantity: line.quantity,
    max_quantity: line.max_quantity,
    unit_purchase_price: line.unit_purchase_price,
    current_sale_price: line.sale_price ?? null,
    sale_price: line.sale_price ?? '',
    sale_name: '',
    renaming: false,
    selected: true,
});

const receivedRows = ref([]);
const buildReceivedRows = () => {
    receivedRows.value = (currentSupplier.value?.orders ?? []).flatMap(
        (order) => order.lines.map((line) => toRow({ ...line, order_uuid: order.uuid })),
    );
    if (orderUuid.value && !(currentSupplier.value?.orders ?? []).some((order) => order.uuid === orderUuid.value)) orderUuid.value = '';
};
buildReceivedRows();
watch(supplierUuid, () => { orderUuid.value = ''; buildReceivedRows(); });
watch(() => props.pending, () => {
    if (!props.pending.some((supplier) => supplier.uuid === supplierUuid.value)) supplierUuid.value = props.pending[0]?.uuid ?? '';
    buildReceivedRows();
    if (!props.pending.length) mode.value = 'manual';
});

const visibleReceived = computed(() => receivedRows.value.filter((row) => !orderUuid.value || row.order_uuid === orderUuid.value));
const selectedReceived = computed(() => visibleReceived.value.filter((row) => row.selected));

// --- Entrée sans commande ------------------------------------------------------
// Le catalogue est affiché en entier dès l'ouverture : on coche ce qui est
// arrivé. La recherche ne fait que filtrer cette liste, elle ne la remplit
// pas — un tableau vide en attendant une recherche cache le travail à faire.
const manual = ref({ origin: '', supplier_uuid: '', operation: 'ENTREE' });
const productQuery = ref('');

const manualRows = ref(props.medicines.map((medicine, index) => ({
    key: `m${index}`,
    medicine_uuid: medicine.uuid,
    name: medicine.name,
    code: medicine.code,
    unit: medicine.unit,
    supplier_uuids: medicine.supplier_uuids ?? [],
    is_new: !medicine.lots?.length,
    known_lots: medicine.lots ?? [],
    lot_number: '',
    expires_at: '',
    quantity: 1,
    max_quantity: null,
    unit_purchase_price: null,
    current_sale_price: medicine.sale_price ?? null,
    sale_price: medicine.sale_price ?? '',
    sale_name: '',
    renaming: false,
    selected: false,
})));

const visibleManual = computed(() => {
    const query = productQuery.value.trim().toLowerCase();

    return manualRows.value.filter((row) => (!manual.value.supplier_uuid || row.supplier_uuids.includes(manual.value.supplier_uuid))
        && (!query || `${row.name} ${row.code}`.toLowerCase().includes(query)));
});
const selectedManual = computed(() => manualRows.value.filter((row) => row.selected));

const supplierOptions = computed(() => [
    { value: '', label: 'Aucun fournisseur' },
    ...props.suppliers.map((supplier) => ({ value: supplier.uuid, label: supplier.name })),
]);

// --- Validation commune --------------------------------------------------------
const rows = computed(() => (mode.value === 'received' ? selectedReceived.value : selectedManual.value));
const units = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0));
const purchaseValue = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.quantity) || 0) * (Number(row.unit_purchase_price) || 0), 0));
const newProducts = computed(() => rows.value.filter((row) => row.is_new).length);
const priceUpdates = computed(() => rows.value.filter((row) => row.sale_price !== '' && row.sale_price !== null
    && Number(row.sale_price) !== Number(row.current_sale_price ?? NaN)).length);
const incomplete = computed(() => rows.value.filter((row) => !String(row.lot_number ?? '').trim() || !row.expires_at
    || !(Number(row.quantity) >= 1) || (row.max_quantity && Number(row.quantity) > row.max_quantity)).length);

const blockReason = computed(() => {
    if (mode.value === 'manual' && manual.value.origin.trim().length < 3) return 'Indiquez d’où vient la marchandise.';
    if (!rows.value.length) return mode.value === 'received' ? 'Cochez au moins une ligne.' : 'Ajoutez au moins un produit.';
    if (incomplete.value) return `${incomplete.value} ligne${incomplete.value > 1 ? 's' : ''} sans lot, sans péremption ou avec une quantité invalide.`;

    return '';
});

const form = useForm({});
const submitted = ref([]);
const confirming = ref(false);

// Les erreurs du serveur désignent une position dans ce qui a été envoyé :
// on les ramène sur la ligne du tableau qu'elles concernent.
const rowErrors = computed(() => {
    const prefix = mode.value === 'received' ? 'lines' : 'entries';
    const byRow = {};
    Object.entries(form.errors).forEach(([key, message]) => {
        const match = key.match(new RegExp(`^${prefix}\\.(\\d+)\\.`));
        const row = match ? submitted.value[Number(match[1])] : null;
        if (row) (byRow[row] ??= []).push(message);
    });

    return byRow;
});
const generalErrors = computed(() => Object.entries(form.errors)
    .filter(([key]) => !/^(lines|entries)\.\d+\./.test(key))
    .map(([, message]) => message));

const salePrice = (row) => (props.capabilities.can_set_sale_price && row.sale_price !== '' && row.sale_price !== null ? row.sale_price : null);
const saleName = (row) => (props.capabilities.can_rename_medicine && row.sale_name?.trim() ? row.sale_name.trim() : null);

const submit = () => {
    submitted.value = rows.value.map((row) => row.key);
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            // Ce qui vient d'entrer se décoche : la liste reste, prête pour la suite.
            manualRows.value.forEach((row) => { row.selected = false; row.lot_number = ''; row.expires_at = ''; row.quantity = 1; });
        },
        onError: () => { confirming.value = false; },
    };

    if (mode.value === 'received') {
        form.transform(() => ({
            lines: rows.value.map((row) => ({
                uuid: row.uuid,
                quantity: Number(row.quantity),
                lot_number: String(row.lot_number).trim(),
                expires_at: row.expires_at,
                sale_price: salePrice(row),
                sale_name: saleName(row),
            })),
        })).post('/pharmacy/stock/entries/received', options);

        return;
    }

    form.transform(() => ({
        supplier_uuid: manual.value.supplier_uuid || null,
        origin: manual.value.origin.trim(),
        entries: rows.value.map((row) => ({
            medicine_uuid: row.medicine_uuid,
            operation: manual.value.operation,
            lot_number: String(row.lot_number).trim(),
            expires_at: row.expires_at,
            quantity: Number(row.quantity),
            sale_price: salePrice(row),
            sale_name: saleName(row),
        })),
    })).post('/pharmacy/stock/entries/batch', options);
};

const switchMode = (value) => {
    mode.value = value;
    form.clearErrors();
};
const siteName = computed(() => page.props.site?.name || page.props.site?.code || '');
</script>

<template>
    <Head title="Entrée en stock" />

    <div class="w-full space-y-6 pb-28">
        <Breadcrumb :items="[{ label: 'Médicaments & stock', href: '/pharmacy/stock' }, { label: 'Entrée en stock' }]" />

        <PageHeader
            eyebrow="Pharmacie"
            title="Entrée en stock"
            description="La marchandise réceptionnée arrive déjà remplie : relisez, corrigez si besoin, validez. La date et le rangement sont enregistrés automatiquement."
            icon="package"
            tone="emerald"
        >
            <template #actions>
                <Button :as="Link" href="/pharmacy/purchase-orders?status=TO_RECEIVE" variant="outline"><Truck class="h-4 w-4" />Commandes à réceptionner</Button>
            </template>
        </PageHeader>

        <!-- Deux façons d'entrer, un seul écran -->
        <div class="grid gap-3 sm:grid-cols-2" role="tablist" aria-label="Type d’entrée">
            <button
                type="button"
                role="tab"
                :aria-selected="mode === 'received'"
                :class="cn('group flex items-center gap-4 rounded-2xl border p-4 text-start transition', mode === 'received' ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-card hover:border-primary/40')"
                @click="switchMode('received')"
            >
                <span :class="cn('grid h-12 w-12 shrink-0 place-items-center rounded-xl', mode === 'received' ? 'bg-primary text-primary-foreground' : 'bg-primary/10 text-primary')"><PackageCheck class="h-6 w-6" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-heading text-base font-bold text-foreground">Marchandise réceptionnée</span>
                    <span class="block text-sm text-muted-foreground">Livraisons de commandes, déjà contrôlées à la réception.</span>
                </span>
                <Badge :tone="pendingLines ? 'warning' : 'neutral'">{{ pendingLines }} en attente</Badge>
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="mode === 'manual'"
                :class="cn('group flex items-center gap-4 rounded-2xl border p-4 text-start transition', mode === 'manual' ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-card hover:border-primary/40')"
                @click="switchMode('manual')"
            >
                <span :class="cn('grid h-12 w-12 shrink-0 place-items-center rounded-xl', mode === 'manual' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')"><PackagePlus class="h-6 w-6" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-heading text-base font-bold text-foreground">Entrée sans commande</span>
                    <span class="block text-sm text-muted-foreground">Don, stock de départ, dépannage d’un confrère.</span>
                </span>
            </button>
        </div>

        <div v-if="generalErrors.length" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">
            <p v-for="message in generalErrors" :key="message">{{ message }}</p>
        </div>

        <!-- ============ Marchandise réceptionnée ============ -->
        <template v-if="mode === 'received'">
            <section v-if="!pending.length" class="rounded-2xl border border-dashed border-border bg-card px-6 py-14 text-center">
                <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300"><CheckCircle2 class="h-7 w-7" /></span>
                <p class="mt-4 font-heading text-lg font-bold text-foreground">Tout est rangé</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">Aucune marchandise réceptionnée n’attend l’entrée en stock. Une livraison apparaîtra ici dès que sa réception sera enregistrée.</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <Button :as="Link" href="/pharmacy/purchase-orders?status=TO_RECEIVE"><Truck class="h-4 w-4" />Réceptionner une commande</Button>
                    <Button variant="outline" @click="switchMode('manual')"><PackagePlus class="h-4 w-4" />Entrée sans commande</Button>
                </div>
            </section>

            <div v-else class="grid items-start gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
                <!-- Fournisseurs dont une livraison attend -->
                <aside class="rounded-2xl border border-border bg-card p-2 shadow-sm lg:sticky lg:top-20">
                    <p class="px-3 pb-2 pt-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Fournisseurs</p>
                    <button
                        v-for="supplier in pending"
                        :key="supplier.uuid"
                        type="button"
                        :class="cn('flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition', supplier.uuid === supplierUuid ? 'bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-muted')"
                        @click="supplierUuid = supplier.uuid"
                    >
                        <span :class="cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg', supplier.uuid === supplierUuid ? 'bg-primary text-primary-foreground' : 'bg-muted')"><Building2 class="h-4 w-4" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-foreground">{{ supplier.name }}</span>
                            <span class="block text-xs">{{ supplier.orders_count }} commande{{ supplier.orders_count > 1 ? 's' : '' }}</span>
                        </span>
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">{{ supplier.lines_count }}</span>
                    </button>
                </aside>

                <section class="rounded-2xl border border-border bg-card shadow-sm">
                    <header class="flex flex-col gap-3 border-b border-border p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="font-heading text-lg font-bold text-foreground">{{ currentSupplier?.name }}</h2>
                                <p class="text-sm text-muted-foreground">Décochez ce qui n’est pas encore rangé ; corrigez directement dans le tableau.</p>
                            </div>
                            <Badge tone="info">{{ selectedReceived.length }} / {{ visibleReceived.length }} ligne{{ visibleReceived.length > 1 ? 's' : '' }} cochée{{ selectedReceived.length > 1 ? 's' : '' }}</Badge>
                        </div>
                        <div v-if="(currentSupplier?.orders.length ?? 0) > 1" class="flex max-w-full gap-1.5 overflow-x-auto">
                            <button type="button" :class="cn('shrink-0 rounded-full border px-3 py-1 text-xs font-semibold transition', !orderUuid ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:bg-muted')" @click="orderUuid = ''">
                                Toutes les commandes
                            </button>
                            <button
                                v-for="order in currentSupplier.orders"
                                :key="order.uuid"
                                type="button"
                                :class="cn('shrink-0 rounded-full border px-3 py-1 font-mono text-xs font-semibold transition', orderUuid === order.uuid ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:bg-muted')"
                                @click="orderUuid = order.uuid"
                            >{{ order.order_number }} · {{ order.lines.length }}</button>
                        </div>
                        <p v-else-if="currentSupplier" class="text-xs text-muted-foreground">Commande <span class="font-mono font-semibold text-foreground">{{ currentSupplier.orders[0].order_number }}</span></p>
                    </header>

                    <StockEntryTable
                        :rows="visibleReceived"
                        mode="received"
                        :can-set-sale-price="capabilities.can_set_sale_price"
                        :can-rename="capabilities.can_rename_medicine"
                        :can-see-cost="capabilities.can_view_cost"
                        :errors="rowErrors"
                    />
                </section>
            </div>
        </template>

        <!-- ============ Entrée sans commande ============ -->
        <template v-else>
            <section class="rounded-2xl border border-border bg-card p-5 shadow-sm">
                <div class="grid gap-4 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-semibold text-foreground">D’où vient cette marchandise ? <span class="text-red-500">*</span></span>
                        <Input v-model="manual.origin" size="lg" maxlength="150" placeholder="Ex. don de l’hôpital de district, bon de livraison BL-0142" />
                        <span class="mt-1 block text-xs text-muted-foreground">Rangé automatiquement dans le stock de la pharmacie{{ siteName ? ` — ${siteName}` : '' }}.</span>
                    </label>
                    <div v-if="capabilities.can_view_suppliers && suppliers.length" class="block">
                        <span class="mb-1.5 block text-sm font-semibold text-foreground">Fournisseur <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                        <Select v-model="manual.supplier_uuid" :options="supplierOptions" placeholder="Aucun fournisseur" />
                        <span class="mt-1 block text-xs text-muted-foreground">Choisi, seuls ses produits sont proposés.</span>
                    </div>
                </div>
                <label v-if="capabilities.can_create_lot" class="mt-4 flex w-fit cursor-pointer items-center gap-2.5 rounded-lg border border-border px-3 py-2 text-sm">
                    <input v-model="manual.operation" type="checkbox" true-value="STOCK_INITIAL" false-value="ENTREE" class="h-4 w-4 accent-[hsl(var(--primary))]">
                    <span><span class="font-semibold text-foreground">Stock de départ</span> <span class="text-muted-foreground">— lots déjà présents à la mise en service</span></span>
                </label>
            </section>

            <section class="rounded-2xl border border-border bg-card shadow-sm">
                <header class="flex flex-col gap-3 border-b border-border p-5 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="font-heading text-base font-bold text-foreground">Produits de la pharmacie</h2>
                        <p class="text-sm text-muted-foreground">Cochez ce qui est arrivé, puis indiquez lot, péremption et quantité.</p>
                    </div>
                    <IconInput v-model="productQuery" :icon="Search" class="md:max-w-xs" placeholder="Filtrer par nom ou code…" autocomplete="off" />
                </header>

                <StockEntryTable
                    v-if="visibleManual.length"
                    :rows="visibleManual"
                    mode="manual"
                    :can-set-sale-price="capabilities.can_set_sale_price"
                    :can-rename="capabilities.can_rename_medicine"
                    :can-see-cost="false"
                    :errors="rowErrors"
                />
                <div v-else class="px-6 py-12 text-center">
                    <Inbox class="mx-auto h-8 w-8 text-muted-foreground" />
                    <p class="mt-3 font-semibold text-foreground">{{ productQuery.trim() || manual.supplier_uuid ? 'Aucun produit ne correspond' : 'Aucun médicament actif' }}</p>
                    <p class="mt-1 text-sm text-muted-foreground">{{ productQuery.trim() || manual.supplier_uuid ? 'Modifiez la recherche ou le fournisseur choisi.' : 'Un médicament doit d’abord exister au catalogue de la clinique.' }}</p>
                </div>

                <footer v-if="visibleManual.length" class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                    {{ visibleManual.length }} produit{{ visibleManual.length > 1 ? 's' : '' }} affiché{{ visibleManual.length > 1 ? 's' : '' }} · {{ selectedManual.length }} coché{{ selectedManual.length > 1 ? 's' : '' }}
                </footer>
            </section>
        </template>

        <!-- Barre d'action -->
        <div v-if="mode === 'manual' || pending.length" class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80 lg:ps-[var(--sidebar-width,0px)]">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                    <p class="text-sm text-muted-foreground"><span class="text-xl font-bold tabular-nums text-foreground">{{ rows.length }}</span> ligne{{ rows.length > 1 ? 's' : '' }} · <span class="font-semibold text-foreground">{{ formatNumber(units) }}</span> unité{{ units > 1 ? 's' : '' }}</p>
                    <p v-if="capabilities.can_view_cost && purchaseValue" class="text-sm text-muted-foreground">Valeur d’achat <span class="font-semibold tabular-nums text-foreground">{{ formatMoney(purchaseValue) }}</span></p>
                    <p v-if="blockReason" class="text-sm text-amber-600 dark:text-amber-400">{{ blockReason }}</p>
                </div>
                <Button size="lg" :disabled="Boolean(blockReason) || form.processing" @click="confirming = true">
                    <PackageCheck class="h-4 w-4" />Valider l’entrée en stock<ArrowRight class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <ConfirmModal
            v-model:open="confirming"
            title="Valider l’entrée en stock ?"
            description="Les quantités s’ajoutent au stock et deviennent disponibles tout de suite. Une entrée validée ne se modifie plus : une erreur se corrige ensuite par une correction de stock."
            confirm-label="Valider l’entrée"
            tone="success"
            :processing="form.processing"
            @confirm="submit"
        >
            <dl class="divide-y divide-border rounded-xl border border-border text-sm">
                <div class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">{{ mode === 'received' ? 'Fournisseur' : 'Provenance' }}</dt>
                    <dd class="text-end font-semibold text-foreground">{{ mode === 'received' ? currentSupplier?.name : manual.origin }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Produits</dt>
                    <dd class="font-semibold text-foreground">{{ rows.length }} ligne{{ rows.length > 1 ? 's' : '' }} · {{ formatNumber(units) }} unité{{ units > 1 ? 's' : '' }}</dd>
                </div>
                <div v-if="newProducts" class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Nouveaux produits</dt>
                    <dd class="font-semibold text-amber-600 dark:text-amber-400">{{ newProducts }}</dd>
                </div>
                <div v-if="priceUpdates" class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Prix de vente fixés ou changés</dt>
                    <dd class="font-semibold text-foreground">{{ priceUpdates }}</dd>
                </div>
                <div v-if="mode === 'manual' && manual.operation === 'STOCK_INITIAL'" class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Type</dt>
                    <dd class="font-semibold text-foreground">Stock de départ</dd>
                </div>
            </dl>
        </ConfirmModal>
    </div>
</template>
