<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, ArrowRight, Banknote, Building2, CalendarDays, Check, ClipboardCheck, FileSpreadsheet, FileText, Hash, Minus,
    PackageCheck, PackagePlus, PackageX, Pencil, Pill, Plus, Search, Sparkles, TriangleAlert, X,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import Checkbox from '@/Components/Shadcn/Checkbox.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Input from '@/Components/Shadcn/Input.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import SupplierInvoiceFields from '@/Components/Pharmacy/SupplierInvoiceFields.vue';
import { cn } from '@/lib/cn';
import { formatDate, localToday } from '@/utilities/date';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-175 — réceptionner, c'est constater ce qui est arrivé : quantités,
 * lots et péremptions lus sur les boîtes. Rien n'entre au stock ici ; c'est
 * l'écran « Entrée en stock » qui range la marchandise.
 *
 * La facture du fournisseur se saisit dans la foulée si elle accompagne la
 * livraison ; sinon la réception reste « facture en attente ». La date de
 * réception est celle du serveur : elle n'est pas demandée.
 */
const props = defineProps({
    order: Object,
    can: Object,
    // ADR-179 — chez qui d'autre trouver un article que ce fournisseur ne
    // livrera pas, et ce qu'il peut livrer sans qu'on l'ait commandé.
    alternatives: { type: Object, default: () => ({}) },
    offOrderProducts: { type: Array, default: () => [] },
});

const step = ref(1);
const confirming = ref(false);
const today = localToday();

/* ADR-179 — une ligne hors commande n'a pas d'identifiant de commande : il lui
 * faut sa propre clé de rendu, stable tant que la page vit. */
let nextKey = 0;
const keyFor = () => `off-${nextKey += 1}`;

/*
 * ADR-176 — le n° de lot et la péremption se recopient de la boîte : rien ne
 * les invente. Seul un lot que la pharmacie tient déjà porte une péremption
 * enregistrée ; la saisir une seconde fois n'apprend rien à personne.
 * Le `nextTick` est nécessaire : `@input` sur un composant est écouté avant
 * que `v-model` ait posé la nouvelle valeur.
 */
const knownLot = (line) => (line._known_lots ?? []).find(
    (lot) => lot.lot_number.toLocaleLowerCase() === String(line.lot_number ?? '').trim().toLocaleLowerCase(),
);
const onLotInput = async (line) => {
    await nextTick();
    const lot = knownLot(line);
    if (lot?.expires_at) line.expires_at = lot.expires_at;
};

const form = useForm({
    notes: '',
    lines: props.order.lines.map((line) => ({
        _key: `order-${line.id}`,
        purchase_order_line_id: line.id,
        medicine_uuid: null,
        supplier_catalog_item_uuid: null,
        quantity_received: line.quantity_remaining,
        lot_number: '',
        expires_at: '',
        unit_purchase_price: props.can.record_cost ? line.unit_price : null,
        notes: '',
        sale_name: '',
        _received: true,
        _renaming: false,
        _name: line.medicine_name,
        _code: line.medicine_code,
        _unit: line.unit,
        _is_new: line.is_new,
        _remaining: line.quantity_remaining,
        _ordered: line.quantity_ordered,
        _unit_price: line.unit_price,
        _known_lots: line.known_lots ?? [],
        _off_order: false,
        _medicine_uuid: line.medicine_uuid,
    })),
    invoice: {
        invoice_number: '',
        invoice_date: today,
        due_date: '',
        total_amount: '',
        notes: '',
        attachment: null,
    },
});

const kept = computed(() => form.lines.filter((line) => line._received));
const units = computed(() => kept.value.reduce((sum, line) => sum + (Number(line.quantity_received) || 0), 0));
const receivedValue = computed(() => kept.value.reduce(
    (sum, line) => sum + (Number(line.quantity_received) || 0) * (Number(line.unit_purchase_price ?? line._unit_price) || 0), 0,
));
const newProducts = computed(() => kept.value.filter((line) => line._is_new).length);
const partial = computed(() => form.lines.some((line) => !line._off_order && !line._received)
    || kept.value.some((line) => !line._off_order && Number(line.quantity_received) < line._remaining));
const offOrderLines = computed(() => kept.value.filter((line) => line._off_order));

const step1Error = computed(() => {
    if (!kept.value.length) return 'Cochez au moins un produit réellement arrivé.';
    const incomplete = kept.value.filter((line) => !line.lot_number.trim() || !line.expires_at
        || !(Number(line.quantity_received) >= 1)
        // Rien ne borne un article livré hors commande : aucune ligne de
        // commande ne dit ce qui était attendu.
        || (!line._off_order && Number(line.quantity_received) > line._remaining)).length;

    return incomplete ? `${incomplete} produit${incomplete > 1 ? 's' : ''} sans lot, sans péremption ou avec une quantité impossible.` : '';
});
const invoiceStarted = computed(() => Boolean(form.invoice.invoice_number.trim() || form.invoice.total_amount || form.invoice.attachment));
const invoiceError = computed(() => {
    if (!invoiceStarted.value) return '';
    if (!form.invoice.invoice_number.trim()) return 'Indiquez le numéro de la facture.';
    if (!(Number(form.invoice.total_amount) > 0)) return 'Indiquez le montant de la facture.';

    return '';
});

/*
 * ADR-179 — descendre à 0 veut dire « ce produit n'est pas dans la
 * livraison » : la ligne se décoche d'elle-même, au lieu de rester cochée
 * avec une quantité que le serveur refuserait. Recocher rétablit ce que la
 * commande attend encore.
 */
const ceilingOf = (line) => (line._off_order ? Number.POSITIVE_INFINITY : line._remaining);

const setQuantity = (line, value) => {
    const next = Math.min(ceilingOf(line), Math.max(0, Number(value) || 0));
    line.quantity_received = next;
    if (next === 0) line._received = false;
};

const stepLine = (line, delta) => setQuantity(line, (Number(line.quantity_received) || 0) + delta);
const onQuantityInput = (line) => setQuantity(line, line.quantity_received);

const toggleLine = (line, received) => {
    line._received = received;
    if (received && !(Number(line.quantity_received) >= 1)) {
        line.quantity_received = line._off_order ? 1 : line._remaining;
    }
};

/* Point 4 — un article livré qui n'était pas commandé. La commande n'est pas
 * réécrite (ADR-098) : la réception constate ce qui est arrivé. */
const offOrderPicker = ref(false);
const offOrderSearch = ref('');
/*
 * ADR-182 — il se choisit dans le catalogue actif de ce fournisseur, et
 * nulle part ailleurs : le livreur apporte ce que son fournisseur vend. Une
 * ligne pas encore reprise par la clinique l'y fait entrer : réceptionner
 * suffit. Seul un refus individuel la laisse montrée mais pas proposée.
 */
const alreadyOnReceipt = computed(() => new Set(form.lines.map((line) => line._catalog_item_uuid).filter(Boolean)));
const offOrderChoices = computed(() => {
    const needle = offOrderSearch.value.trim().toLocaleLowerCase();

    return props.offOrderProducts
        .filter((product) => !alreadyOnReceipt.value.has(product.catalog_item_uuid))
        .filter((product) => !needle
            || `${product.name ?? ''} ${product.code ?? ''} ${product.family ?? ''}`.toLocaleLowerCase().includes(needle));
});
const offOrderShown = computed(() => offOrderChoices.value.slice(0, 60));
const canPick = (product) => product.linked || props.can.create_medicine;
const offOrderTotal = computed(() => offOrderChoices.value.length);

const addOffOrder = (product) => {
    form.lines.push({
        _key: keyFor(),
        purchase_order_line_id: null,
        medicine_uuid: null,
        supplier_catalog_item_uuid: product.catalog_item_uuid,
        quantity_received: 1,
        lot_number: '',
        expires_at: '',
        unit_purchase_price: props.can.record_cost ? product.quoted_price : null,
        notes: '',
        sale_name: '',
        _received: true,
        _renaming: false,
        _name: product.name,
        _code: product.code,
        _unit: product.unit,
        _is_new: !product.linked,
        _remaining: null,
        _ordered: null,
        _unit_price: product.quoted_price,
        _known_lots: [],
        _off_order: true,
        _catalog_item_uuid: product.catalog_item_uuid,
    });
    offOrderPicker.value = false;
    offOrderSearch.value = '';
};

const removeOffOrder = (line) => {
    form.lines = form.lines.filter((candidate) => candidate._key !== line._key);
};

const proposedTotal = computed(() => (props.can.see_cost && receivedValue.value ? receivedValue.value.toFixed(2) : null));

/*
 * Point 5 — le montant proposé reste celui de ce qui est RÉELLEMENT arrivé :
 * sur une livraison partielle, c'est lui qui est juste. Ce que la commande
 * engage est montré à côté, et l'écart signalé — jamais bloqué : le papier du
 * fournisseur fait foi (ADR-175).
 */
const orderTotal = computed(() => (props.order.total_amount === null ? null : Number(props.order.total_amount)));
const alreadyInvoiced = computed(() => Number(props.order.invoiced_amount ?? 0));
const invoiceGap = computed(() => {
    const typed = Number(form.invoice.total_amount);
    if (!props.can.see_cost || !(typed > 0) || orderTotal.value === null) return null;

    const outstanding = orderTotal.value - alreadyInvoiced.value;
    const gap = typed - receivedValue.value;
    if (Math.abs(gap) < 0.01) return null;

    return { gap, outstanding, received: receivedValue.value };
});

/* Point 2 — l'article n'est pas venu : le signaler, et voir chez qui d'autre
 * le trouver. Le geste appartient à la réception (ADR-176). */
const shortage = ref(null);
const shortageReason = ref('');
const shortageSending = ref(false);
const openShortage = (line) => {
    shortage.value = line;
    shortageReason.value = '';
};
const alternativesFor = (line) => props.alternatives?.[line._medicine_uuid] ?? [];

const submitShortage = () => {
    if (!shortageReason.value.trim() || shortageSending.value) return;
    shortageSending.value = true;
    router.post(
        `/pharmacy/purchase-orders/${props.order.uuid}/lines/${shortage.value.purchase_order_line_id}/shortage`,
        { reason: shortageReason.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => {
                shortageSending.value = false;
                shortage.value = null;
            },
        },
    );
};

const submit = () => {
    form.transform((data) => ({
        notes: data.notes,
        lines: data.lines
            .filter((line) => line._received)
            .map((line) => ({
                purchase_order_line_id: line.purchase_order_line_id,
                medicine_uuid: line.medicine_uuid,
                supplier_catalog_item_uuid: line.supplier_catalog_item_uuid,
                quantity_received: Number(line.quantity_received),
                lot_number: line.lot_number.trim(),
                expires_at: line.expires_at,
                unit_purchase_price: line.unit_purchase_price,
                notes: line.notes || null,
                sale_name: line.sale_name?.trim() || null,
            })),
        invoice: invoiceStarted.value ? data.invoice : null,
    })).post(`/pharmacy/purchase-orders/${props.order.uuid}/receipts`, {
        forceFormData: true,
        onError: () => {
            confirming.value = false;
            if (Object.keys(form.errors).some((key) => key.startsWith('lines'))) step.value = 1;
        },
    });
};

const lineError = (index, field) => form.errors[`lines.${index}.${field}`];
const steps = [
    { number: 1, label: 'Ce qui est arrivé', icon: PackageCheck },
    { number: 2, label: 'Facture fournisseur', icon: FileText },
];
</script>

<template>
    <Head :title="`Réceptionner ${order.order_number}`" />

    <div class="w-full space-y-6 pb-28">
        <Breadcrumb :items="[
            { label: 'Achats', href: '/pharmacy/purchase-orders' },
            { label: order.order_number, href: `/pharmacy/purchase-orders/${order.uuid}` },
            { label: 'Réceptionner' },
        ]" />

        <PageHeader
            eyebrow="Achats"
            title="Réceptionner la marchandise"
            description="Recopiez le lot et la péremption inscrits sur les boîtes, et la quantité réellement arrivée. La marchandise entrera au stock à l’étape « Entrée en stock »."
            icon="package"
            tone="violet"
        />

        <!-- Progression -->
        <ol class="flex items-center gap-3">
            <li v-for="(item, index) in steps" :key="item.number" class="flex flex-1 items-center gap-3">
                <button
                    type="button"
                    :disabled="item.number === 2 && (Boolean(step1Error) || !can.record_invoice)"
                    :class="cn('flex flex-1 items-center gap-3 rounded-xl border px-4 py-3 text-start transition disabled:opacity-50',
                               step === item.number ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-card hover:border-primary/40')"
                    @click="step = item.number"
                >
                    <span :class="cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold',
                                     step > item.number ? 'bg-emerald-600 text-white' : step === item.number ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')">
                        <Check v-if="step > item.number" class="h-4 w-4" /><template v-else>{{ item.number }}</template>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-foreground">{{ item.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ item.number === 1 ? `${kept.length} produit${kept.length > 1 ? 's' : ''} · ${formatNumber(units)} unité${units > 1 ? 's' : ''}` : (invoiceStarted ? form.invoice.invoice_number || 'en cours de saisie' : 'facultatif — peut attendre') }}</span>
                    </span>
                </button>
                <ArrowRight v-if="index === 0" class="hidden h-4 w-4 shrink-0 text-muted-foreground sm:block" />
            </li>
        </ol>

        <!-- La commande, en lecture -->
        <section class="grid gap-3 rounded-2xl border border-border bg-card p-5 shadow-sm sm:grid-cols-3">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Fournisseur</p>
                    <p class="truncate font-semibold text-foreground">{{ order.supplier }}</p>
                    <p v-if="order.supplier_contact" class="truncate text-xs text-muted-foreground">{{ order.supplier_contact }}</p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-muted text-muted-foreground"><Hash class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Commande</p>
                    <Link :href="`/pharmacy/purchase-orders/${order.uuid}`" class="font-mono font-semibold text-primary hover:underline">{{ order.order_number }}</Link>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-muted text-muted-foreground"><CalendarDays class="h-5 w-5" /></span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Réception</p>
                    <p class="font-semibold text-foreground">{{ formatDate(today) }}</p>
                    <p class="text-xs text-muted-foreground">datée automatiquement</p>
                </div>
            </div>
        </section>

        <!-- ÉTAPE 1 -->
        <section v-show="step === 1" class="rounded-2xl border border-border bg-card shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border p-5">
                <div>
                    <h2 class="font-heading text-lg font-bold text-foreground">Ce qui est arrivé</h2>
                    <p class="text-sm text-muted-foreground">Décochez un produit qui n’est pas dans la livraison ; il restera attendu sur la commande.</p>
                </div>
                <Badge v-if="partial" tone="warning">Réception partielle</Badge>
            </header>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="w-10 px-4 py-3" />
                            <th scope="col" class="px-4 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Pill class="h-3.5 w-3.5" aria-hidden="true" />Produit</span></th>
                            <th scope="col" class="w-44 px-3 py-3 text-start"><span class="inline-flex items-center gap-1.5"><PackageCheck class="h-3.5 w-3.5" aria-hidden="true" />Quantité arrivée</span></th>
                            <th scope="col" class="w-40 px-3 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Hash class="h-3.5 w-3.5" aria-hidden="true" />N° de lot</span></th>
                            <th scope="col" class="w-40 px-3 py-3 text-start"><span class="inline-flex items-center gap-1.5"><CalendarDays class="h-3.5 w-3.5" aria-hidden="true" />Péremption</span></th>
                            <th v-if="can.record_cost" scope="col" class="w-36 px-3 py-3 text-start"><span class="inline-flex items-center gap-1.5"><Banknote class="h-3.5 w-3.5" aria-hidden="true" />Prix d’achat</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template v-for="(line, index) in form.lines" :key="line._key">
                            <tr :class="cn('align-top transition-colors', line._received ? 'hover:bg-muted/20' : 'bg-muted/30 opacity-60')">
                                <td class="px-4 py-3.5">
                                    <Checkbox
                                        :model-value="line._received"
                                        :aria-label="`${line._name} est arrivé`"
                                        @update:model-value="toggleLine(line, $event)"
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold text-foreground">{{ line.sale_name?.trim() || line._name }}</p>
                                        <Badge v-if="line._is_new" tone="warning"><Sparkles class="h-3 w-3" />Nouveau produit</Badge>
                                        <Badge v-if="line._off_order" tone="info">Hors commande</Badge>
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        <span class="font-mono">{{ line._code }}</span><span v-if="line._unit"> · {{ line._unit }}</span>
                                        <span v-if="line._off_order"> · n’était pas dans la commande</span>
                                        <span v-else> · commandé {{ formatNumber(line._ordered) }}</span>
                                    </p>
                                    <div v-if="can.rename && line._received && line._is_new" class="mt-1.5">
                                        <div v-if="line._renaming" class="flex max-w-sm items-center gap-1.5">
                                            <Input v-model="line.sale_name" class="h-9 text-sm" maxlength="255" :placeholder="line._name" aria-label="Nom à la pharmacie" />
                                            <Button type="button" size="icon-xs" variant="ghost" aria-label="Garder le nom du fournisseur" @click="line.sale_name = ''; line._renaming = false"><X class="h-3.5 w-3.5" /></Button>
                                        </div>
                                        <button v-else type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline" @click="line._renaming = true">
                                            <Pencil class="h-3 w-3" />Donner son nom à la pharmacie
                                        </button>
                                    </div>
                                    <Input v-if="line._received" v-model="line.notes" class="mt-2 h-8 max-w-sm text-xs" maxlength="500" placeholder="Remarque sur ce produit (ex. 1 boîte abîmée)" />

                                    <!-- ADR-179 — l'article n'est pas venu du tout : son reliquat
                                         cesse d'être attendu, et la commande peut se clore. -->
                                    <div v-if="!line._off_order && !line._received && can.shortage" class="mt-2 flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs font-semibold text-destructive hover:underline"
                                            @click="openShortage(line)"
                                        >
                                            <PackageX class="h-3.5 w-3.5" />Le fournisseur ne le livrera pas
                                        </button>
                                        <span v-if="alternativesFor(line).length" class="text-[11px] text-muted-foreground">
                                            {{ alternativesFor(line).length }} autre{{ alternativesFor(line).length > 1 ? 's' : '' }} fournisseur{{ alternativesFor(line).length > 1 ? 's' : '' }} le propose{{ alternativesFor(line).length > 1 ? 'nt' : '' }}
                                        </span>
                                    </div>

                                    <button
                                        v-if="line._off_order"
                                        type="button"
                                        class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground hover:text-destructive hover:underline"
                                        @click="removeOffOrder(line)"
                                    >
                                        <X class="h-3.5 w-3.5" />Retirer de la réception
                                    </button>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center">
                                        <Button type="button" size="icon-xs" variant="outline" class="rounded-e-none" tabindex="-1" :disabled="Number(line.quantity_received) <= 0" aria-label="Moins" @click="stepLine(line, -1)"><Minus class="h-3 w-3" /></Button>
                                        <input
                                            v-model.number="line.quantity_received"
                                            type="number"
                                            min="0"
                                            :max="line._off_order ? undefined : line._remaining"
                                            :class="cn('h-7 w-16 border-y border-input bg-card text-center text-sm font-semibold tabular-nums text-foreground outline-none focus:border-primary', !line._off_order && Number(line.quantity_received) > line._remaining && 'border-red-400 text-red-600')"
                                            @input="onQuantityInput(line)"
                                        >
                                        <Button type="button" size="icon-xs" variant="outline" class="rounded-s-none" tabindex="-1" :disabled="!line._off_order && Number(line.quantity_received) >= line._remaining" aria-label="Plus" @click="stepLine(line, 1)"><Plus class="h-3 w-3" /></Button>
                                    </div>
                                    <p v-if="line._off_order" class="mt-1 text-[11px] text-muted-foreground">livré sans avoir été commandé</p>
                                    <p v-else-if="!line._received" class="mt-1 text-[11px] font-semibold text-amber-600 dark:text-amber-400">pas dans cette livraison</p>
                                    <p v-else class="mt-1 text-[11px] text-muted-foreground">{{ formatNumber(line._remaining) }} encore attendu{{ line._remaining > 1 ? 's' : '' }}</p>
                                    <p v-if="lineError(index, 'quantity_received')" class="mt-1 text-[11px] text-destructive">{{ lineError(index, 'quantity_received') }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <Input
                                        v-model="line.lot_number"
                                        class="h-9 font-mono text-sm"
                                        maxlength="100"
                                        placeholder="Lu sur la boîte"
                                        :list="line._known_lots?.length ? `recu-lots-${index}` : undefined"
                                        :disabled="!line._received"
                                        @input="onLotInput(line)"
                                    />
                                    <datalist v-if="line._known_lots?.length" :id="`recu-lots-${index}`"><option v-for="lot in line._known_lots" :key="lot.uuid" :value="lot.lot_number" /></datalist>
                                    <p v-if="knownLot(line)" class="mt-1 text-[11px] text-emerald-600 dark:text-emerald-400">Lot connu : sa péremption est reprise</p>
                                    <p v-if="lineError(index, 'lot_number')" class="mt-1 text-[11px] text-destructive">{{ lineError(index, 'lot_number') }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <DatePicker v-model="line.expires_at" size="sm" :disabled="!line._received" />
                                    <p v-if="lineError(index, 'expires_at')" class="mt-1 text-[11px] text-destructive">{{ lineError(index, 'expires_at') }}</p>
                                </td>
                                <td v-if="can.record_cost" class="px-3 py-3">
                                    <span class="relative block">
                                        <Input v-model="line.unit_purchase_price" type="number" min="0" step="0.01" class="h-9 pe-11 text-end text-sm tabular-nums" :disabled="!line._received" />
                                        <span class="pointer-events-none absolute inset-y-0 end-2.5 flex items-center text-[11px] text-muted-foreground">MGA</span>
                                    </span>
                                    <p class="mt-1 text-[11px] text-muted-foreground">{{ line._off_order ? 'Prix du fournisseur' : 'Repris de la commande' }}</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Point 4 — un article arrivé sans avoir été commandé. Il se
                 choisit dans les mêmes deux sources qu'à la commande (ADR-098) :
                 ce que la clinique tient, et le catalogue ACTIF du fournisseur. -->
            <div v-if="can.shortage" class="border-t border-border p-5">
                <div v-if="!offOrderPicker" class="flex flex-wrap items-center gap-3">
                    <Button type="button" variant="outline" size="sm" @click="offOrderPicker = true">
                        <PackagePlus class="h-4 w-4" />Ajouter un article livré hors commande
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        Il est constaté sur cette réception ; la commande n’est pas modifiée.
                    </p>
                </div>

                <div v-else class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border bg-muted/40 px-4 py-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                                <PackagePlus class="h-4.5 w-4.5" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-foreground">Que le fournisseur a-t-il livré en plus ?</p>
                                <p class="text-xs text-muted-foreground">
                                    Produits de {{ order.supplier }} — {{ formatNumber(offOrderTotal) }} proposé{{ offOrderTotal > 1 ? 's' : '' }}.
                                    La commande garde ses lignes et son montant.
                                </p>
                            </div>
                        </div>
                        <Button type="button" size="icon-xs" variant="ghost" aria-label="Fermer" @click="offOrderPicker = false; offOrderSearch = ''">
                            <X class="h-3.5 w-3.5" />
                        </Button>
                    </header>

                    <div class="px-4 py-3">
                        <IconInput v-model="offOrderSearch" :icon="Search" placeholder="Chercher par nom ou référence…" />
                    </div>

                    <div v-if="offOrderShown.length" class="max-h-80 overflow-y-auto border-t border-border">
                        <h4 class="sticky top-0 z-10 flex items-center gap-2 border-b border-border bg-muted/60 px-4 py-2 backdrop-blur">
                            <FileSpreadsheet class="h-3.5 w-3.5 text-muted-foreground" />
                            <span class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Catalogue de {{ order.supplier }}</span>
                            <Badge variant="outline" class="px-1.5 py-0 text-[10px]">{{ formatNumber(offOrderTotal) }}</Badge>
                        </h4>
                        <ul class="divide-y divide-border">
                            <li v-for="product in offOrderShown" :key="product.catalog_item_uuid">
                                <button
                                    type="button"
                                    :disabled="!canPick(product)"
                                    class="flex w-full items-center gap-3 px-4 py-2.5 text-start transition hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="addOffOrder(product)"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-foreground">{{ product.name }}</span>
                                        <span class="mt-0.5 block truncate text-[11px] text-muted-foreground">
                                            <span class="font-mono">{{ product.code }}</span>
                                            <span v-if="product.unit"> · {{ product.unit }}</span>
                                            <span v-if="product.family"> · {{ product.family }}</span>
                                            <span v-if="!canPick(product)"> · nouveau produit : refusé à votre compte (medicines.create)</span>
                                        </span>
                                    </span>
                                    <span v-if="can.see_cost && product.quoted_price" class="shrink-0 text-xs font-semibold tabular-nums text-muted-foreground">
                                        {{ formatMoney(product.quoted_price) }}
                                    </span>
                                    <Plus class="h-4 w-4 shrink-0 text-muted-foreground" />
                                </button>
                            </li>
                        </ul>
                        <p v-if="offOrderTotal > offOrderShown.length" class="px-4 py-2 text-[11px] text-muted-foreground">
                            {{ formatNumber(offOrderTotal - offOrderShown.length) }} autre{{ offOrderTotal - offOrderShown.length > 1 ? 's' : '' }} — affinez la recherche.
                        </p>
                    </div>
                    <p v-else-if="!offOrderProducts.length" class="border-t border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        {{ order.supplier }} n’a pas de catalogue actif : importez-le dans son dossier fournisseur pour pouvoir y choisir un produit livré.
                    </p>
                    <p v-else class="border-t border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        Aucun produit de ce fournisseur ne correspond.
                    </p>
                </div>
            </div>

            <footer class="flex flex-col gap-3 border-t border-border p-5 sm:flex-row sm:items-center sm:justify-between">
                <label class="block w-full max-w-md">
                    <span class="mb-1.5 block text-sm font-semibold text-foreground">Remarque sur la livraison <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                    <Input v-model="form.notes" maxlength="2000" placeholder="Ex. 2 cartons abîmés, chauffeur prévenu" />
                </label>
            </footer>
        </section>

        <!-- ÉTAPE 2 -->
        <section v-show="step === 2" class="rounded-2xl border border-border bg-card shadow-sm">
            <header class="border-b border-border p-5">
                <h2 class="font-heading text-lg font-bold text-foreground">Facture du fournisseur</h2>
                <p class="text-sm text-muted-foreground">Si la facture accompagne la livraison, recopiez-la ici. Sinon, passez : la réception restera « facture en attente » et la facture pourra être enregistrée plus tard.</p>
            </header>
            <div class="p-5">
                <!-- Point 5 — ce que la commande engage, à côté de ce qui est
                     réellement arrivé. Un écart se signale, il ne bloque rien. -->
                <dl v-if="can.see_cost && orderTotal !== null" class="mb-5 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-border bg-muted/30 p-3">
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Commande</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-foreground">{{ formatMoney(orderTotal) }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-muted/30 p-3">
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Reçu sur cette livraison</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-foreground">{{ formatMoney(receivedValue) }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-muted/30 p-3">
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Déjà facturé</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-foreground">{{ formatMoney(alreadyInvoiced) }}</dd>
                    </div>
                </dl>

                <SupplierInvoiceFields :form="form.invoice" :errors="form.errors" prefix="invoice" :proposed-total="proposedTotal" />

                <p
                    v-if="invoiceGap"
                    class="mt-3 flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300"
                >
                    <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        Le montant saisi diffère de <strong>{{ formatMoney(Math.abs(invoiceGap.gap)) }}</strong>
                        de ce qui est arrivé ({{ formatMoney(invoiceGap.received) }}).
                        C’est normal sur une livraison partielle, un article hors commande ou un prix renégocié —
                        le papier du fournisseur fait foi. Vérifiez seulement que c’est bien ce qu’il facture.
                    </span>
                </p>
                <p v-if="invoiceError" class="mt-3 text-sm text-amber-600 dark:text-amber-400">{{ invoiceError }}</p>
            </div>
        </section>

        <!-- Barre d'action -->
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                    <p class="text-sm text-muted-foreground"><span class="text-xl font-bold tabular-nums text-foreground">{{ kept.length }}</span> produit{{ kept.length > 1 ? 's' : '' }} · <span class="font-semibold text-foreground">{{ formatNumber(units) }}</span> unité{{ units > 1 ? 's' : '' }}</p>
                    <p v-if="can.see_cost && receivedValue" class="text-sm text-muted-foreground">Valeur reçue <span class="font-semibold tabular-nums text-foreground">{{ formatMoney(receivedValue) }}</span></p>
                    <p v-if="step === 1 && step1Error" class="text-sm text-amber-600 dark:text-amber-400">{{ step1Error }}</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Button v-if="step === 2" type="button" variant="ghost" @click="step = 1"><ArrowLeft class="h-4 w-4" />Revenir aux produits</Button>
                    <Button :as="Link" :href="`/pharmacy/purchase-orders/${order.uuid}`" variant="outline">Annuler</Button>
                    <Button v-if="step === 1 && can.record_invoice" :disabled="Boolean(step1Error)" @click="step = 2">
                        Continuer vers la facture<ArrowRight class="h-4 w-4" />
                    </Button>
                    <Button v-else size="lg" :disabled="Boolean(step1Error) || Boolean(invoiceError) || form.processing" @click="confirming = true">
                        <ClipboardCheck class="h-4 w-4" />{{ invoiceStarted ? 'Enregistrer réception et facture' : 'Enregistrer la réception' }}
                    </Button>
                </div>
            </div>
        </div>

        <ConfirmModal
            v-model:open="confirming"
            title="Enregistrer cette réception ?"
            :description="invoiceStarted
                ? 'La réception et la facture sont enregistrées ensemble. La marchandise n’entre pas encore au stock : elle attendra l’écran « Entrée en stock ».'
                : 'La marchandise n’entre pas encore au stock : elle attendra l’écran « Entrée en stock ». La facture reste en attente.'"
            confirm-label="Enregistrer la réception"
            tone="success"
            :processing="form.processing"
            @confirm="submit"
        >
            <dl class="divide-y divide-border rounded-xl border border-border text-sm">
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Fournisseur</dt><dd class="text-end font-semibold text-foreground">{{ order.supplier }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Commande</dt><dd class="font-mono font-semibold text-foreground">{{ order.order_number }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Reçu</dt>
                    <dd class="font-semibold text-foreground">{{ kept.length }} produit{{ kept.length > 1 ? 's' : '' }} · {{ formatNumber(units) }} unité{{ units > 1 ? 's' : '' }}<span v-if="partial" class="text-amber-600 dark:text-amber-400"> · partielle</span></dd>
                </div>
                <div v-if="newProducts" class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-muted-foreground">Nouveaux produits</dt><dd class="font-semibold text-amber-600 dark:text-amber-400">{{ newProducts }}</dd></div>
                <div class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Facture</dt>
                    <dd class="text-end font-semibold text-foreground">{{ invoiceStarted ? `${form.invoice.invoice_number} · ${formatMoney(form.invoice.total_amount)}` : 'En attente' }}</dd>
                </div>
                <div v-if="offOrderLines.length" class="flex justify-between gap-4 px-4 py-2.5">
                    <dt class="text-muted-foreground">Hors commande</dt>
                    <dd class="font-semibold text-foreground">{{ offOrderLines.length }} article{{ offOrderLines.length > 1 ? 's' : '' }}</dd>
                </div>
            </dl>
        </ConfirmModal>

        <!-- ADR-179 — l'article ne viendra pas : son reliquat cesse d'être
             attendu, et la commande peut se clore. Rien n'est effacé. -->
        <ConfirmModal
            :open="shortage !== null"
            title="Cet article ne sera pas livré ?"
            description="Son reliquat cesse d’être attendu sur cette commande, qui pourra alors se clore. La ligne reste visible avec sa quantité commandée et son prix ; rien n’est effacé."
            confirm-label="Signaler la rupture"
            tone="danger"
            :processing="shortageSending"
            :disabled="!shortageReason.trim()"
            @update:open="shortage = $event ? shortage : null"
            @confirm="submitShortage"
        >
            <div v-if="shortage" class="space-y-4">
                <p class="text-sm font-semibold text-foreground">{{ shortage._name }}</p>

                <label class="block">
                    <span class="mb-1.5 block text-sm font-semibold text-foreground">Pourquoi ne sera-t-il pas livré ?</span>
                    <Input v-model="shortageReason" maxlength="1000" placeholder="Ex. rupture chez le fournisseur jusqu’en décembre" />
                </label>

                <div v-if="alternativesFor(shortage).length" class="rounded-xl border border-border bg-muted/30 p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Ce produit chez d’autres fournisseurs</p>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="option in alternativesFor(shortage)" :key="option.supplier_uuid" class="flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate text-foreground">{{ option.supplier_name }}</span>
                            <span class="shrink-0 font-semibold tabular-nums text-muted-foreground">
                                {{ option.quoted_price ? formatMoney(option.quoted_price) : (option.has_price ? '—' : 'prix à définir') }}
                            </span>
                        </li>
                    </ul>
                    <p class="mt-2 text-[11px] text-muted-foreground">
                        Le même produit, jamais un équivalent deviné. Passer commande reste un geste à part, depuis le dossier du fournisseur.
                    </p>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    Aucun autre fournisseur ne propose ce produit dans le référentiel.
                </p>
            </div>
        </ConfirmModal>
    </div>
</template>
