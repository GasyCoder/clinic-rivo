<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight, Boxes, Inbox, PackageCheck, Search, Sparkles, Tag, Truck,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import StockEntryTable from '@/Components/Pharmacy/StockEntryTable.vue';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-182 — ce qui entre au stock vient d'une livraison réceptionnée, et de
 * rien d'autre. Rangé, le produit devient disponible et vendable : il quitte
 * cet écran et apparaît dans « Médicaments & stock ».
 *
 * Plusieurs livraisons peuvent attendre en même temps, et certaines portent
 * des produits que la pharmacie n'a encore jamais vendus. L'écran se lit donc
 * en deux axes (amendement du 2026-09-24) :
 *
 *   - les livraisons, à gauche — on en range une à la fois, la plus ancienne
 *     d'abord ; « Toutes » reste possible ;
 *   - l'état des produits, en cartes — tout ce qui est à ranger, les nouveaux
 *     produits à mettre en vente, ceux qui n'ont pas encore de prix de vente.
 *
 * Fournisseur, commande, lots, péremptions et quantités viennent de la
 * réception, déjà remplis et cochés. Aucune date n'est demandée : le serveur
 * date l'entrée (ADR-175).
 */
const props = defineProps({
    capabilities: { type: Object, required: true },
    pending: { type: Array, default: () => [] },
    initialSupplier: { type: String, default: '' },
    initialOrder: { type: String, default: '' },
});

// --- Les livraisons qui attendent d'être rangées -----------------------------
const deliveries = computed(() => props.pending.flatMap((supplier) => (supplier.orders ?? []).map((order) => {
    const lines = order.lines ?? [];
    const received = lines.map((line) => line.received_at).filter(Boolean).sort();

    return {
        key: order.uuid,
        supplier_uuid: supplier.uuid,
        supplier_name: supplier.name,
        order_number: order.order_number,
        receipts: [...new Set(lines.map((line) => line.receipt_number).filter(Boolean))],
        received_at: received[0] ?? null,
        lines_count: lines.length,
        new_count: lines.filter((line) => line.is_new).length,
    };
})).sort((a, b) => String(a.received_at ?? '').localeCompare(String(b.received_at ?? ''))));

// Une ligne par produit réceptionné, sous l'intitulé de sa livraison.
const receivedRows = ref([]);
/*
 * Reconstruite quand la file change — une entrée validée. Une ligne encore à
 * l'écran garde ce qu'on y a saisi : ranger une livraison ne doit pas effacer
 * le lot qu'on venait de corriger sur une autre.
 */
const buildReceivedRows = () => {
    const onScreen = new Map(receivedRows.value.map((row) => [row.uuid, row]));

    receivedRows.value = props.pending.flatMap((supplier) => (supplier.orders ?? []).flatMap(
        (order) => order.lines.map((line) => onScreen.get(line.uuid) ?? ({
            key: `r:${line.uuid}`,
            group: `${supplier.name} · commande ${order.order_number}`,
            uuid: line.uuid,
            order_uuid: order.uuid,
            order_number: order.order_number,
            supplier_uuid: supplier.uuid,
            supplier_name: supplier.name,
            medicine_uuid: line.medicine_uuid,
            name: line.medicine_name,
            code: line.medicine_code,
            unit: line.unit,
            is_new: line.is_new,
            receipt_number: line.receipt_number,
            notes: line.notes,
            known_lots: line.known_lots ?? [],
            lot_number: line.lot_number,
            expires_at: line.expires_at,
            quantity: line.quantity,
            max_quantity: line.max_quantity,
            off_order: Boolean(line.off_order),
            unit_purchase_price: line.unit_purchase_price,
            current_sale_price: line.sale_price ?? null,
            sale_price: line.sale_price ?? '',
            sale_name: '',
            renaming: false,
            selected: true,
        })),
    ));
};
buildReceivedRows();
watch(() => props.pending, buildReceivedRows);

// --- La livraison qu'on range ---------------------------------------------------
const initialDelivery = () => {
    if (deliveries.value.some((delivery) => delivery.key === props.initialOrder)) return props.initialOrder;
    const ofSupplier = deliveries.value.find((delivery) => delivery.supplier_uuid === props.initialSupplier);
    if (props.initialSupplier && ofSupplier) return ofSupplier.key;

    // La plus ancienne d'abord ; une seule livraison se montre telle quelle.
    return deliveries.value[0]?.key ?? '';
};
const deliveryUuid = ref(initialDelivery());
const currentDelivery = computed(() => deliveries.value.find((delivery) => delivery.key === deliveryUuid.value) ?? null);

// Une livraison rangée quitte la liste : on passe à la suivante.
watch(deliveries, (list) => {
    if (deliveryUuid.value && !list.some((delivery) => delivery.key === deliveryUuid.value)) {
        deliveryUuid.value = list[0]?.key ?? '';
    }
});

const inScope = (row) => !deliveryUuid.value || row.order_uuid === deliveryUuid.value;
const scopedRows = computed(() => receivedRows.value.filter(inScope));

// --- L'état des produits ------------------------------------------------------
/*
 * Les comptes viennent de la file complète servie par le serveur — elle n'est
 * pas paginée — et chacun est ce que donnerait un clic dans la livraison
 * choisie. « Sans prix de vente » lit le prix enregistré, jamais celui qu'on
 * est en train de taper : une ligne ne doit pas disparaître pendant la saisie.
 */
const status = ref('all');
const STATUS_RULES = {
    all: () => true,
    new: (row) => row.is_new,
    unpriced: (row) => row.current_sale_price === null,
};
const tiles = computed(() => [
    {
        value: 'all',
        label: 'À ranger',
        hint: 'lignes réceptionnées',
        icon: Boxes,
        tone: 'primary',
        count: scopedRows.value.length,
        active: status.value === 'all',
    },
    {
        value: 'new',
        label: 'Nouveaux produits',
        hint: 'jamais vendus : nom et prix à donner',
        icon: Sparkles,
        tone: 'amber',
        count: scopedRows.value.filter(STATUS_RULES.new).length,
        active: status.value === 'new',
    },
    {
        value: 'unpriced',
        label: 'Sans prix de vente',
        hint: 'pas encore vendables',
        icon: Tag,
        tone: 'red',
        count: scopedRows.value.filter(STATUS_RULES.unpriced).length,
        active: status.value === 'unpriced',
    },
    {
        value: 'deliveries',
        label: 'Livraisons en attente',
        hint: 'réceptionnées, pas encore rangées',
        icon: Truck,
        tone: 'neutral',
        count: deliveries.value.length,
        filterable: false,
    },
]);
const selectStatus = (value) => { status.value = status.value === value ? 'all' : value; };

// --- Recherche ----------------------------------------------------------------
const query = ref('');
const matches = (row) => {
    const needle = query.value.trim().toLowerCase();

    return !needle || `${row.name} ${row.code}`.toLowerCase().includes(needle);
};

const visibleRows = computed(() => scopedRows.value.filter(
    (row) => STATUS_RULES[status.value](row) && matches(row),
));
// Ce qui est coché dans la livraison choisie part, même masqué par un filtre
// d'état ou une recherche : on ne perd pas une ligne parce qu'on a regardé
// ailleurs après l'avoir cochée. Les autres livraisons ne partent pas.
const rows = computed(() => scopedRows.value.filter((row) => row.selected));

const deliveryLabel = (delivery) => `${delivery.supplier_name} · commande ${delivery.order_number}`;

/*
 * ADR-176 — arriver avec `?commande=` sur une livraison déjà rangée laissait
 * croire à un écran vide sans un mot. On dit ce qui s'est passé.
 */
const nothingPendingForRequest = computed(() => Boolean(
    (props.initialOrder && !deliveries.value.some((delivery) => delivery.key === props.initialOrder))
    || (props.initialSupplier && !props.initialOrder && !deliveries.value.some((delivery) => delivery.supplier_uuid === props.initialSupplier)),
));

// --- Validation ----------------------------------------------------------------
const units = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0));
const purchaseValue = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.quantity) || 0) * (Number(row.unit_purchase_price) || 0), 0));
const newProducts = computed(() => rows.value.filter((row) => row.is_new).length);
const priceUpdates = computed(() => rows.value.filter((row) => row.sale_price !== '' && row.sale_price !== null
    && Number(row.sale_price) !== Number(row.current_sale_price ?? NaN)).length);
const stillUnpriced = computed(() => rows.value.filter((row) => row.current_sale_price === null
    && (row.sale_price === '' || row.sale_price === null)).length);
const incomplete = computed(() => rows.value.filter((row) => !String(row.lot_number ?? '').trim() || !row.expires_at
    || !(Number(row.quantity) >= 1) || (row.max_quantity && Number(row.quantity) > row.max_quantity)).length);

const blockReason = computed(() => {
    if (!receivedRows.value.length) return 'Aucune livraison réceptionnée n’attend d’être rangée.';
    if (!rows.value.length) return 'Cochez au moins une ligne à faire entrer en stock.';
    if (incomplete.value) return `${incomplete.value} ligne${incomplete.value > 1 ? 's' : ''} sans lot, sans péremption ou avec une quantité invalide.`;

    return '';
});

const form = useForm({});
const submitted = ref([]);
const confirming = ref(false);

// Les erreurs du serveur désignent une position dans ce qui a été envoyé : on
// les ramène sur la ligne du tableau qu'elles concernent.
const rowErrors = computed(() => {
    const byRow = {};
    Object.entries(form.errors).forEach(([key, message]) => {
        const match = key.match(/^lines\.(\d+)\./);
        const row = match ? submitted.value[Number(match[1])] : null;
        if (row) (byRow[row] ??= []).push(message);
    });

    return byRow;
});
const generalErrors = computed(() => Object.entries(form.errors)
    .filter(([key]) => !/^lines\.\d+\./.test(key))
    .map(([, message]) => message));

const salePrice = (row) => (props.capabilities.can_set_sale_price && row.sale_price !== '' && row.sale_price !== null ? row.sale_price : null);
const saleName = (row) => (props.capabilities.can_rename_medicine && row.sale_name?.trim() ? row.sale_name.trim() : null);

const submit = () => {
    const selected = rows.value;
    submitted.value = selected.map((row) => row.key);

    form.transform(() => ({
        lines: selected.map((row) => ({
            uuid: row.uuid,
            quantity: Number(row.quantity),
            lot_number: String(row.lot_number).trim(),
            expires_at: row.expires_at,
            sale_price: salePrice(row),
            sale_name: saleName(row),
        })),
    })).post('/pharmacy/stock/entries/batch', {
        preserveScroll: true,
        onSuccess: () => { confirming.value = false; },
        onError: () => { confirming.value = false; },
    });
};
</script>

<template>
    <Head title="Entrée en stock" />

    <div class="w-full space-y-6 pb-28">
        <Breadcrumb :items="[{ label: 'Médicaments & stock', href: '/pharmacy/stock' }, { label: 'Entrée en stock' }]" />

        <PageHeader
            eyebrow="Pharmacie"
            title="Entrée en stock"
            description="Les livraisons réceptionnées attendent ici d’être rangées. Choisissez une livraison, relisez ce qui est déjà rempli, donnez leur nom et leur prix de vente aux nouveaux produits, validez : ils deviennent disponibles et vendables dans « Médicaments & stock »."
            icon="package"
            tone="emerald"
        >
            <template #actions>
                <Button :as="Link" href="/pharmacy/purchase-orders?status=TO_RECEIVE" variant="outline"><Truck class="h-4 w-4" />Commandes à réceptionner</Button>
            </template>
        </PageHeader>

        <div v-if="generalErrors.length" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">
            <p v-for="message in generalErrors" :key="message">{{ message }}</p>
        </div>

        <p
            v-if="nothingPendingForRequest"
            class="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-100"
        >
            <PackageCheck class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Cette livraison est déjà entièrement rangée : il n’y a plus rien à faire entrer au stock pour elle.</span>
        </p>

        <!-- Rien n'attend : avant toute réception, l'écran le dit, et dit
             comment faire entrer du stock. -->
        <section v-if="!receivedRows.length" class="rounded-2xl border border-border bg-card px-6 py-12 text-center shadow-sm">
            <Inbox class="mx-auto h-8 w-8 text-muted-foreground" />
            <p class="mt-3 font-semibold text-foreground">Aucune livraison n’attend d’être rangée</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                Le stock n’entre que depuis une livraison réceptionnée : réceptionnez d’abord la commande, ses produits arriveront ici déjà remplis.
            </p>
            <Button :as="Link" href="/pharmacy/purchase-orders?status=TO_RECEIVE" variant="outline" class="mt-4"><Truck class="h-4 w-4" />Commandes à réceptionner</Button>
        </section>

        <template v-else>
            <!-- L'état des produits de la livraison choisie : la carte est le filtre. -->
            <QueueCounters :tiles="tiles" @select="selectStatus" />

            <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
                <!-- ============ Les livraisons ============ -->
                <aside class="min-w-0">
                    <div class="rounded-2xl border border-border bg-card shadow-sm lg:sticky lg:top-4">
                        <header class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                            <h2 class="inline-flex items-center gap-2 text-sm font-bold text-foreground"><Truck class="h-4 w-4 text-muted-foreground" />Livraisons</h2>
                            <Badge variant="outline">{{ deliveries.length }}</Badge>
                        </header>
                        <nav class="flex gap-2 overflow-x-auto p-2 lg:max-h-[calc(100vh-16rem)] lg:flex-col lg:overflow-y-auto" aria-label="Livraisons à ranger">
                            <button
                                v-if="deliveries.length > 1"
                                type="button"
                                :aria-pressed="!deliveryUuid"
                                :class="cn('flex shrink-0 items-center justify-between gap-3 rounded-xl border px-3 py-2.5 text-start transition lg:w-full', !deliveryUuid ? 'border-primary bg-primary/5 ring-1 ring-ring/25' : 'border-transparent hover:bg-muted/60')"
                                @click="deliveryUuid = ''"
                            >
                                <span class="text-sm font-semibold text-foreground">Toutes les livraisons</span>
                                <span class="text-xs tabular-nums text-muted-foreground">{{ receivedRows.length }}</span>
                            </button>
                            <button
                                v-for="delivery in deliveries"
                                :key="delivery.key"
                                type="button"
                                :aria-pressed="deliveryUuid === delivery.key"
                                :class="cn('min-w-56 shrink-0 rounded-xl border px-3 py-2.5 text-start transition lg:w-full lg:min-w-0', deliveryUuid === delivery.key ? 'border-primary bg-primary/5 ring-1 ring-ring/25' : 'border-transparent hover:bg-muted/60')"
                                @click="deliveryUuid = delivery.key"
                            >
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-semibold text-foreground">{{ delivery.supplier_name }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ delivery.lines_count }} ligne{{ delivery.lines_count > 1 ? 's' : '' }}</span>
                                </span>
                                <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">
                                    commande <span class="font-mono">{{ delivery.order_number }}</span>
                                    <template v-if="delivery.received_at"> · reçue le {{ formatDate(delivery.received_at) }}</template>
                                </span>
                                <span v-if="delivery.new_count" class="mt-1.5 inline-flex">
                                    <Badge tone="warning"><Sparkles class="h-3 w-3" />{{ delivery.new_count }} nouveau{{ delivery.new_count > 1 ? 'x' : '' }}</Badge>
                                </span>
                            </button>
                        </nav>
                    </div>
                </aside>

                <!-- ============ Les lignes de la livraison ============ -->
                <section class="min-w-0 rounded-2xl border border-border bg-card shadow-sm">
                    <header class="flex flex-col gap-3 border-b border-border p-5 md:flex-row md:items-center md:justify-between">
                        <div class="min-w-0">
                            <h2 class="truncate font-heading text-base font-bold text-foreground">
                                {{ currentDelivery ? deliveryLabel(currentDelivery) : 'Toutes les livraisons' }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                <template v-if="currentDelivery?.receipts.length">Réception {{ currentDelivery.receipts.join(', ') }} · </template>
                                Décochez ce qui n’est pas encore sur l’étagère, corrigez sur place ce que la boîte dit autrement.
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Badge :tone="rows.length ? 'info' : 'neutral'">{{ rows.length }} cochée{{ rows.length > 1 ? 's' : '' }}</Badge>
                            <IconInput v-model="query" :icon="Search" class="md:w-64" placeholder="Filtrer par nom ou code…" autocomplete="off" />
                        </div>
                    </header>

                    <StockEntryTable
                        v-if="visibleRows.length"
                        :rows="visibleRows"
                        :can-set-sale-price="capabilities.can_set_sale_price"
                        :can-rename="capabilities.can_rename_medicine"
                        :can-see-cost="capabilities.can_view_cost"
                        :errors="rowErrors"
                    />
                    <div v-else class="px-6 py-12 text-center">
                        <Search class="mx-auto h-8 w-8 text-muted-foreground" />
                        <p class="mt-3 font-semibold text-foreground">Aucune ligne ne correspond</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            <template v-if="status !== 'all'">Aucun produit de cet état dans cette livraison. </template>
                            Modifiez la recherche, l’état ou la livraison.
                        </p>
                    </div>

                    <footer v-if="visibleRows.length" class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                        {{ visibleRows.length }} ligne{{ visibleRows.length > 1 ? 's' : '' }} affichée{{ visibleRows.length > 1 ? 's' : '' }}
                        sur {{ scopedRows.length }} dans {{ currentDelivery ? 'cette livraison' : 'les livraisons en attente' }}
                    </footer>
                </section>
            </div>
        </template>

        <!-- Barre d'action -->
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80 lg:ps-[var(--sidebar-width,0px)]">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                    <p class="text-sm text-muted-foreground">
                        <span class="text-xl font-bold tabular-nums text-foreground">{{ rows.length }}</span> ligne{{ rows.length > 1 ? 's' : '' }} ·
                        <span class="font-semibold text-foreground">{{ formatNumber(units) }}</span> unité{{ units > 1 ? 's' : '' }}
                        <template v-if="currentDelivery"> · {{ deliveryLabel(currentDelivery) }}</template>
                    </p>
                    <p v-if="capabilities.can_view_cost && purchaseValue" class="text-sm text-muted-foreground">Valeur d’achat <span class="font-semibold tabular-nums text-foreground">{{ formatMoney(purchaseValue) }}</span></p>
                    <p v-if="blockReason" class="text-sm text-amber-600 dark:text-amber-400">{{ blockReason }}</p>
                </div>
                <Button size="lg" :disabled="Boolean(blockReason) || form.processing" @click="confirming = true">
                    <PackageCheck class="h-4 w-4" />{{ currentDelivery ? 'Ranger cette livraison' : 'Valider l’entrée en stock' }}<ArrowRight class="h-4 w-4" />
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
                    <dt class="text-muted-foreground">Livraison</dt>
                    <dd class="text-end font-semibold text-foreground">{{ currentDelivery ? deliveryLabel(currentDelivery) : 'Toutes les livraisons cochées' }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">À ranger</dt>
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
                <div v-if="stillUnpriced" class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Rangés sans prix de vente</dt>
                    <dd class="text-end font-semibold text-red-600 dark:text-red-400">{{ stillUnpriced }} — pas encore vendables</dd>
                </div>
            </dl>
        </ConfirmModal>
    </div>
</template>
