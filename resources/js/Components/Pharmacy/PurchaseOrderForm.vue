<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import {
    Building2, CalendarDays, FileSpreadsheet, Hash, Mail, PackageOpen, Phone, Save, Search, Send, User,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDate } from '@/utilities/date';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';
import { openSupplierOrderMail } from '@/utilities/supplierOrderMail';
import { usePage } from '@inertiajs/vue3';

/**
 * ADR-098 — le formulaire de commande d'achat, partagé par la clinique et le
 * portail. La clinique choisit le fournisseur ; le portail l'ouvre depuis un
 * dossier, le fournisseur est donc fixé.
 *
 * Tout ce que le fournisseur propose est affiché dès l'ouverture : commander,
 * c'est écrire une quantité en face d'un produit. La recherche ne fait que
 * filtrer cette liste — un tableau vide en attendant une recherche cacherait
 * le catalogue qu'on vient justement consulter.
 *
 * Le numéro et la date de commande ne se saisissent pas : le numéro est
 * attribué à l'enregistrement, la date est celle de l'envoi au fournisseur
 * (ADR-175). L'écran les montre, il ne les demande pas.
 */
const props = defineProps({
    // null quand la page fixe le fournisseur.
    suppliers: { type: Array, default: null },
    supplierUuid: { type: String, default: '' },
    supplierName: { type: String, default: '' },
    // Coordonnées du fournisseur fixé (portail) : nom du contact, téléphone, e-mail.
    supplier: { type: Object, default: null },
    // Ce que ce fournisseur peut livrer : les médicaments déjà au catalogue
    // de la clinique (`uuid`) et les lignes de son catalogue pas encore
    // reprises (`catalog_item_uuid`). Chacun peut porter son prix.
    medicines: { type: Array, default: () => [] },
    submitUrl: { type: Function, required: true },
    cancelHref: { type: String, required: true },
    // Un brouillon corrigé : ses lignes remplissent les quantités, envoi en PUT.
    order: { type: Object, default: null },
    catalogHref: { type: String, default: null },
    canSend: { type: Boolean, default: true },
});
const emit = defineEmits(['supplier-change']);

const productKey = (product) => (product.uuid ? `med:${product.uuid}` : `cat:${product.catalog_item_uuid}`);
const splitKey = (key) => (key.startsWith('med:')
    ? { medicine_uuid: key.slice(4), supplier_catalog_item_uuid: null }
    : { medicine_uuid: null, supplier_catalog_item_uuid: key.slice(4) });

const form = useForm({
    supplier_uuid: props.supplierUuid,
    expected_delivery_at: props.order?.expected_delivery_at ?? '',
    notes: props.order?.notes ?? '',
    send: false,
    lines: [],
});

// Une ligne par produit proposé, quantité à zéro au départ.
const toRow = (product) => {
    const ordered = props.order?.lines?.find((line) => line.medicine_uuid === product.uuid);

    return {
        key: productKey(product),
        product_group: product.product_group,
        name: product.name,
        code: product.code,
        is_new: product.in_clinic_catalog === false,
        quantity: ordered?.quantity_ordered ?? 0,
        unit_price: String(ordered?.unit_price ?? product.quoted_price ?? ''),
        supplier_price: product.quoted_price ?? null,
        editing_price: false,
    };
};

const rows = ref([]);
const buildRows = () => {
    // Un produit déjà commandé que le fournisseur ne propose plus reste en
    // tête : sinon corriger le brouillon le ferait disparaître en silence.
    const missing = (props.order?.lines ?? [])
        .filter((line) => !props.medicines.some((product) => product.uuid === line.medicine_uuid))
        .map((line) => ({
            key: `med:${line.medicine_uuid}`,
            product_group: `med:${line.medicine_uuid}`,
            name: line.medicine_name,
            code: line.medicine_code,
            is_new: false,
            quantity: line.quantity_ordered,
            unit_price: String(line.unit_price),
            supplier_price: null,
            editing_price: false,
        }));

    rows.value = [...missing, ...props.medicines.map(toRow)];
};
buildRows();

watch(() => props.supplierUuid, (uuid) => {
    if (uuid !== form.supplier_uuid) form.supplier_uuid = uuid;
});
// Changer de fournisseur change la liste : les quantités saisies désignaient
// les produits d'un autre, elles repartent à zéro.
watch(() => props.medicines, buildRows);

const currentSupplier = computed(() => props.supplier
    ?? props.suppliers?.find((supplier) => supplier.uuid === form.supplier_uuid)
    ?? (props.supplierName ? { name: props.supplierName } : null));
const supplierOptions = computed(() => (props.suppliers ?? []).map((supplier) => ({ value: supplier.uuid, label: supplier.name })));

// --- Filtre -----------------------------------------------------------------
const query = ref('');
const visibleRows = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return rows.value.filter((row) => !needle || `${row.name ?? ''} ${row.code ?? ''}`.toLowerCase().includes(needle));
});

// --- Produits retenus --------------------------------------------------------
const chosen = computed(() => rows.value.filter((row) => Number(row.quantity) > 0));
// Le serveur dit quelles entrées sont le même produit (`product_group`) : un
// catalogue liste souvent un article sous deux références, et une commande
// n'accepte qu'une ligne par produit (ADR-098).
const takenGroups = computed(() => new Set(chosen.value.map((row) => row.product_group).filter(Boolean)));
const blockedByTwin = (row) => Number(row.quantity) === 0 && row.product_group && takenGroups.value.has(row.product_group);

const step = (row, delta) => { row.quantity = Math.max(0, (Number(row.quantity) || 0) + delta); };
const lineTotal = (row) => (Number(row.quantity) || 0) * (Number(row.unit_price) || 0);
const total = computed(() => chosen.value.reduce((sum, row) => sum + lineTotal(row), 0));
const units = computed(() => chosen.value.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0));
const newProducts = computed(() => chosen.value.filter((row) => row.is_new).length);
const unpriced = computed(() => chosen.value.filter((row) => !(Number(row.unit_price) > 0)).length);

const ready = computed(() => Boolean(form.supplier_uuid) && chosen.value.length > 0 && unpriced.value === 0);
const blockReason = computed(() => {
    if (!form.supplier_uuid) return 'Choisissez d’abord le fournisseur.';
    if (!chosen.value.length) return 'Indiquez une quantité en face d’au moins un produit.';
    if (unpriced.value) return `${unpriced.value} produit${unpriced.value > 1 ? 's' : ''} sans prix d’achat.`;

    return '';
});

// Les erreurs du serveur désignent une position dans ce qui a été envoyé :
// on les ramène sur la ligne du tableau qu'elles concernent.
const submitted = ref([]);
const rowErrors = computed(() => {
    const byRow = {};
    Object.entries(form.errors).forEach(([key, message]) => {
        const match = key.match(/^lines\.(\d+)\./);
        const rowKey = match ? submitted.value[Number(match[1])] : null;
        if (rowKey) (byRow[rowKey] ??= []).push(message);
    });

    return byRow;
});

// --- Envoi ------------------------------------------------------------------
const confirmingSend = ref(false);
/*
 * L'e-mail au fournisseur : un brouillon ouvert dans la messagerie de la
 * personne, jamais un envoi par le serveur (voir utilities/supplierOrderMail).
 * Il accompagne l'envoi, il ne le conditionne pas : sans adresse au dossier du
 * fournisseur, la commande part exactement comme avant et rien n'est proposé.
 */
const page = usePage();
const supplierEmail = computed(() => currentSupplier.value?.email ?? null);
const openMailDraft = (lines, amount) => openSupplierOrderMail(supplierEmail.value, {
    order_number: props.order?.order_number ?? null,
    ordered_on: formatDate(new Date().toISOString().slice(0, 10)),
    expected_delivery_on: form.expected_delivery_at ? formatDate(form.expected_delivery_at) : null,
    total: formatMoney(amount),
    notes: form.notes,
    lines,
}, {
    clinic: page.props.site?.name ?? null,
    author: page.props.auth?.user?.name ?? null,
});

const save = (send) => {
    if (!ready.value) return;
    form.send = send;
    submitted.value = chosen.value.map((row) => row.key);
    const lines = chosen.value.map((row) => ({ quantity: row.quantity, unit: row.unit, name: row.name, code: row.code }));
    const amount = total.value;
    const transformed = form.transform(({ supplier_uuid, lines: _lines, ...data }) => ({
        ...data,
        lines: chosen.value.map((row) => ({
            ...splitKey(row.key),
            quantity_ordered: Number(row.quantity),
            unit_price: row.unit_price,
        })),
    }));
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            confirmingSend.value = false;
            // La commande est partie : on ouvre le brouillon avec ce qui vient
            // d'être envoyé, pas avec un formulaire déjà vidé.
            if (send) openMailDraft(lines, amount);
        },
        onError: () => { confirmingSend.value = false; },
    };
    if (props.order) transformed.put(props.submitUrl(form.supplier_uuid), options);
    else transformed.post(props.submitUrl(form.supplier_uuid), options);
};

// --- Livraison attendue : un délai, pas un calendrier -------------------------
const today = new Date().toISOString().slice(0, 10);
const deliveryTerms = [
    { label: 'Non précisée', days: null },
    { label: 'Sous 3 jours', days: 3 },
    { label: 'Sous 1 semaine', days: 7 },
    { label: 'Sous 2 semaines', days: 14 },
];
const inDays = (days) => {
    if (days === null) return '';
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
};
const customDelivery = ref(Boolean(props.order?.expected_delivery_at));
const chip = (active) => cn(
    'rounded-full border px-3 py-1 text-xs font-semibold transition',
    active ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:bg-muted',
);
</script>

<template>
    <form class="space-y-5" @submit.prevent="save(false)">
        <ValidationErrorSummary :errors="form.errors" />

        <!-- Fournisseur et références de la commande -->
        <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
            <div class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Fournisseur</p>
                <div v-if="suppliers" class="mt-3 max-w-md">
                    <Select
                        :model-value="form.supplier_uuid"
                        :options="supplierOptions"
                        placeholder="Choisir un fournisseur"
                        @update:model-value="(value) => { form.supplier_uuid = value; emit('supplier-change', value); }"
                    />
                </div>
                <div v-if="currentSupplier" class="mt-3 flex items-start gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary"><Building2 class="h-5 w-5" /></span>
                    <div class="min-w-0">
                        <p class="text-lg font-bold text-foreground">{{ currentSupplier.name }}</p>
                        <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            <span v-if="currentSupplier.contact_name" class="inline-flex items-center gap-1.5"><User class="h-3.5 w-3.5" />{{ currentSupplier.contact_name }}</span>
                            <a v-if="currentSupplier.phone" :href="`tel:${currentSupplier.phone}`" class="inline-flex items-center gap-1.5 hover:text-primary"><Phone class="h-3.5 w-3.5" />{{ currentSupplier.phone }}</a>
                            <a v-if="currentSupplier.email" :href="`mailto:${currentSupplier.email}`" class="inline-flex items-center gap-1.5 hover:text-primary"><Mail class="h-3.5 w-3.5" />{{ currentSupplier.email }}</a>
                            <span v-if="!currentSupplier.contact_name && !currentSupplier.phone && !currentSupplier.email" class="italic">Aucun contact renseigné dans le dossier.</span>
                        </p>
                    </div>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-3 rounded-xl border border-border bg-card p-5 text-sm shadow-sm">
                <div>
                    <dt class="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><Hash class="h-3.5 w-3.5" />N° de commande</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">{{ order?.order_number ?? 'Automatique' }}</dd>
                    <dd v-if="!order" class="text-xs text-muted-foreground">attribué à l’enregistrement</dd>
                </div>
                <div>
                    <dt class="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><CalendarDays class="h-3.5 w-3.5" />Date de commande</dt>
                    <dd class="mt-1 font-semibold text-foreground">{{ formatDate(today) }}</dd>
                    <dd class="text-xs text-muted-foreground">fixée à l’envoi</dd>
                </div>
                <div class="col-span-2">
                    <dt class="mb-1.5 text-xs font-semibold text-muted-foreground">Livraison attendue <span class="font-normal">(facultatif)</span></dt>
                    <dd v-if="!customDelivery" class="flex flex-wrap gap-1.5">
                        <button
                            v-for="term in deliveryTerms"
                            :key="term.label"
                            type="button"
                            :class="chip(form.expected_delivery_at === inDays(term.days))"
                            @click="form.expected_delivery_at = inDays(term.days)"
                        >{{ term.label }}</button>
                        <button type="button" :class="chip(false)" @click="customDelivery = true">Autre date</button>
                    </dd>
                    <dd v-else class="flex items-center gap-2">
                        <DatePicker v-model="form.expected_delivery_at" :min="today" />
                        <Button type="button" size="sm" variant="ghost" @click="form.expected_delivery_at = ''; customDelivery = false">Retour</Button>
                    </dd>
                    <dd v-if="form.expected_delivery_at" class="mt-1 text-xs text-muted-foreground">Attendue le {{ formatDate(form.expected_delivery_at) }}</dd>
                    <dd v-if="form.errors.expected_delivery_at" class="mt-1 text-xs text-destructive">{{ form.errors.expected_delivery_at }}</dd>
                </div>
            </dl>
        </section>

        <!-- Aucun fournisseur / rien à commander -->
        <section v-if="!form.supplier_uuid" class="rounded-xl border border-dashed border-border bg-muted/30 px-6 py-10 text-center">
            <Building2 class="mx-auto h-8 w-8 text-muted-foreground" />
            <p class="mt-3 font-semibold text-foreground">Choisissez d’abord le fournisseur</p>
            <p class="mt-1 text-sm text-muted-foreground">Ses produits s’afficheront ici : ceux que la clinique tient déjà de lui, et ceux de son catalogue.</p>
        </section>

        <section v-else-if="!rows.length" class="rounded-xl border border-amber-200 bg-amber-50 px-6 py-8 text-center dark:border-amber-900 dark:bg-amber-950/30">
            <PackageOpen class="mx-auto h-8 w-8 text-amber-600" />
            <p class="mt-3 font-semibold text-amber-900 dark:text-amber-100">Aucun produit à commander chez ce fournisseur</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-amber-900/90 dark:text-amber-100/90">Son catalogue n’a pas encore été importé. Importez-le : ses produits deviendront commandables directement.</p>
            <Button v-if="catalogHref" :as="Link" :href="catalogHref" class="mt-4"><FileSpreadsheet class="h-4 w-4" />Ouvrir le catalogue du fournisseur</Button>
        </section>

        <!-- Catalogue du fournisseur, affiché en entier -->
        <section v-else class="rounded-xl border border-border bg-card shadow-sm">
            <header class="flex flex-col gap-3 border-b border-border p-5 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="font-heading text-base font-bold text-foreground">Produits de {{ currentSupplier?.name || 'ce fournisseur' }}</h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">Indiquez la quantité voulue en face d’un produit. Le prix d’achat vient de son catalogue.</p>
                </div>
                <IconInput v-model="query" :icon="Search" class="md:max-w-xs" placeholder="Filtrer par nom ou référence…" autocomplete="off" />
            </header>

            <div v-if="visibleRows.length" class="max-h-[32rem] overflow-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="sticky top-0 z-10 bg-muted text-xs font-semibold text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 text-start">Produit</th>
                            <th class="w-40 px-4 py-3 text-center">Quantité</th>
                            <th class="w-48 px-4 py-3 text-end">Prix d’achat unitaire</th>
                            <th class="w-36 px-4 py-3 text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="row in visibleRows"
                            :key="row.key"
                            :class="cn('align-top transition-colors', Number(row.quantity) > 0 ? 'bg-primary/[0.04]' : 'hover:bg-muted/20', rowErrors[row.key]?.length && 'bg-red-50/50 dark:bg-red-950/10')"
                        >
                            <td class="px-5 py-3.5">
                                <p class="font-semibold text-foreground">{{ row.name }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <span class="font-mono">{{ row.code }}</span>
                                    <Badge v-if="row.is_new" variant="secondary" class="px-2 py-0.5">Nouveau au catalogue</Badge>
                                    <span v-if="blockedByTwin(row)" class="text-amber-600 dark:text-amber-400">Déjà commandé sous une autre référence</span>
                                </p>
                                <p v-if="rowErrors[row.key]?.length" class="mt-1 text-xs text-destructive">{{ rowErrors[row.key].join(' ') }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <div :class="cn('mx-auto flex h-10 w-32 overflow-hidden rounded-lg border border-border bg-card', blockedByTwin(row) && 'opacity-40')">
                                    <button type="button" class="w-9 text-lg text-muted-foreground hover:bg-muted disabled:opacity-40" :disabled="blockedByTwin(row) || Number(row.quantity) <= 0" :aria-label="`Diminuer la quantité de ${row.name}`" @click="step(row, -1)">−</button>
                                    <input
                                        v-model.number="row.quantity"
                                        type="number"
                                        min="0"
                                        inputmode="numeric"
                                        :disabled="blockedByTwin(row)"
                                        class="w-full min-w-0 border-0 bg-transparent text-center text-sm font-semibold tabular-nums text-foreground outline-none"
                                        :aria-label="`Quantité de ${row.name}`"
                                    >
                                    <button type="button" class="w-9 text-lg text-muted-foreground hover:bg-muted disabled:opacity-40" :disabled="blockedByTwin(row)" :aria-label="`Augmenter la quantité de ${row.name}`" @click="step(row, 1)">+</button>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-end">
                                <template v-if="!row.editing_price">
                                    <p :class="cn('h-10 font-semibold leading-10 tabular-nums', Number(row.unit_price) > 0 ? 'text-foreground' : 'text-amber-600')">
                                        {{ Number(row.unit_price) > 0 ? formatMoney(row.unit_price) : 'Prix à saisir' }}
                                    </p>
                                    <button type="button" class="text-xs font-semibold text-primary hover:underline" @click="row.editing_price = true">Changer le prix</button>
                                </template>
                                <template v-else>
                                    <div class="relative ms-auto w-40">
                                        <Input v-model="row.unit_price" type="number" min="0.01" step="0.01" inputmode="decimal" :class="cn('pe-12 text-end', !(Number(row.unit_price) > 0) && 'border-amber-400')" :aria-label="`Prix d’achat unitaire de ${row.name}`" />
                                        <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">MGA</span>
                                    </div>
                                    <p v-if="!row.supplier_price" class="mt-1 text-xs text-amber-600">Pas de prix au catalogue : indiquez-le.</p>
                                    <p v-else-if="String(row.unit_price) !== String(row.supplier_price)" class="mt-1 text-xs text-amber-600">Prix négocié · catalogue : {{ formatMoney(row.supplier_price) }}</p>
                                </template>
                            </td>
                            <td class="px-4 py-3.5 text-end text-base font-bold leading-10 tabular-nums text-foreground">
                                {{ Number(row.quantity) > 0 ? formatMoney(lineTotal(row)) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-12 text-center">
                <PackageOpen class="mx-auto h-9 w-9 text-muted-foreground" />
                <p class="mt-3 font-semibold text-foreground">Aucun produit ne correspond à « {{ query }} »</p>
                <p class="mt-1 text-sm text-muted-foreground">Effacez la recherche pour revoir tout le catalogue du fournisseur.</p>
            </div>

            <div class="flex flex-col gap-3 border-t border-border p-5 md:flex-row md:items-end md:justify-between">
                <label class="block w-full max-w-2xl">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Remarque pour le fournisseur <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                    <Input v-model="form.notes" maxlength="2000" placeholder="Ex. livraison urgente, appeler avant de passer" />
                </label>
                <p class="shrink-0 text-xs text-muted-foreground">{{ visibleRows.length }} produit{{ visibleRows.length > 1 ? 's' : '' }} affiché{{ visibleRows.length > 1 ? 's' : '' }} · {{ chosen.length }} retenu{{ chosen.length > 1 ? 's' : '' }}</p>
            </div>
        </section>

        <!-- Barre d'actions collante -->
        <!-- Collée au bas de l'écran tant que le formulaire est visible : le
             total et les deux boutons restent sous les yeux pendant la saisie. -->
        <div class="sticky bottom-3 z-30 rounded-xl border border-border bg-card/95 shadow-[0_-8px_24px_-12px_rgba(0,0,0,0.25)] backdrop-blur">
            <div class="flex w-full flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-baseline gap-4">
                    <div>
                        <p class="text-xs font-semibold text-muted-foreground">Total de la commande</p>
                        <p class="text-2xl font-bold tabular-nums text-foreground">{{ formatMoney(total) }}</p>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ chosen.length }} produit{{ chosen.length > 1 ? 's' : '' }} · {{ formatNumber(units) }} unité{{ units > 1 ? 's' : '' }}
                        <span v-if="blockReason" class="block text-amber-600">{{ blockReason }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button :as="Link" :href="cancelHref" variant="ghost">Annuler</Button>
                    <Button type="submit" variant="outline" size="lg" :disabled="form.processing || !ready">
                        <Save class="h-4 w-4" />Enregistrer en brouillon
                    </Button>
                    <Button v-if="canSend" type="button" size="lg" :disabled="form.processing || !ready" @click="confirmingSend = true">
                        <Send class="h-4 w-4" />Envoyer au fournisseur
                    </Button>
                </div>
            </div>
        </div>

        <ConfirmModal
            :open="confirmingSend"
            title="Envoyer cette commande au fournisseur ?"
            confirm-label="Envoyer la commande"
            :icon="Send"
            :processing="form.processing"
            @update:open="confirmingSend = $event"
            @confirm="save(true)"
        >
            <dl class="space-y-2 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Fournisseur</dt><dd class="font-semibold text-foreground">{{ currentSupplier?.name }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Produits</dt><dd class="font-semibold tabular-nums text-foreground">{{ chosen.length }} ligne{{ chosen.length > 1 ? 's' : '' }} · {{ formatNumber(units) }} unité{{ units > 1 ? 's' : '' }}</dd></div>
                <div v-if="newProducts" class="flex justify-between gap-3"><dt class="text-muted-foreground">Nouveaux au catalogue</dt><dd class="font-semibold tabular-nums text-foreground">{{ newProducts }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-border pt-2"><dt class="text-muted-foreground">Montant total</dt><dd class="text-base font-bold tabular-nums text-foreground">{{ formatMoney(total) }}</dd></div>
            </dl>
            <!-- L'e-mail accompagne l'envoi : il s'ouvre dans la messagerie
                 de la personne, qui relit et envoie depuis sa propre adresse. -->
            <p v-if="supplierEmail" class="mt-3 flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-100">
                <Mail class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Votre messagerie s’ouvrira avec la commande déjà écrite, pour <span class="font-semibold">{{ supplierEmail }}</span>. Relisez-la avant de l’envoyer.</span>
            </p>
            <p v-else class="mt-3 flex items-start gap-2 text-sm text-muted-foreground">
                <Mail class="mt-0.5 h-4 w-4 shrink-0" />
                <span>Aucune adresse e-mail au dossier de ce fournisseur : la commande part dans RIVO, mais aucun brouillon ne s’ouvrira.</span>
            </p>
            <p class="mt-3 text-sm text-muted-foreground">Une fois envoyée, la commande ne peut plus être modifiée : seule une annulation motivée reste possible.</p>
        </ConfirmModal>
    </form>
</template>
