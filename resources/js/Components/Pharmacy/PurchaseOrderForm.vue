<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Dialog from '@/Components/Shadcn/Dialog.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Input from '@/Components/Shadcn/Input.vue';
import Select from '@/Components/Shadcn/Select.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import {
    Building2, CalendarDays, Check, FileSpreadsheet, Hash, ListPlus, Mail, PackageOpen, Phone, Plus, Save, Search, Send, Trash2, User,
} from 'lucide-vue-next';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — le formulaire de commande d'achat, partagé par la clinique et le
 * portail. La clinique choisit le fournisseur ; le portail l'ouvre depuis un
 * dossier, le fournisseur est donc fixé.
 *
 * Le numéro et la date de commande ne se saisissent pas : le numéro est
 * attribué à l'enregistrement, la date est celle de l'envoi au fournisseur
 * (ADR-111). L'écran les montre, il ne les demande pas.
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
    // Un brouillon corrigé : ses lignes remplissent le formulaire, envoi en PUT.
    order: { type: Object, default: null },
    catalogHref: { type: String, default: null },
    canSend: { type: Boolean, default: true },
});

const productKey = (product) => (product.uuid ? `med:${product.uuid}` : `cat:${product.catalog_item_uuid}`);
const splitKey = (key) => (key.startsWith('med:')
    ? { medicine_uuid: key.slice(4), supplier_catalog_item_uuid: null }
    : { medicine_uuid: null, supplier_catalog_item_uuid: key.slice(4) });

const form = useForm({
    supplier_uuid: props.supplierUuid,
    expected_delivery_at: props.order?.expected_delivery_at ?? '',
    notes: props.order?.notes ?? '',
    send: false,
    lines: (props.order?.lines ?? []).map((line) => ({
        product_key: `med:${line.medicine_uuid}`,
        product_group: `med:${line.medicine_uuid}`,
        name: line.medicine_name,
        code: line.medicine_code,
        is_new: false,
        quantity_ordered: line.quantity_ordered,
        unit_price: String(line.unit_price),
        supplier_price: String(line.unit_price),
        editing_price: false,
    })),
});

const emit = defineEmits(['supplier-change']);

// Changer de fournisseur change la liste des produits : les lignes déjà
// saisies désignaient ceux d'un autre, elles repartent à zéro.
watch(() => props.supplierUuid, (uuid) => {
    if (uuid === form.supplier_uuid) return;
    form.supplier_uuid = uuid;
    form.lines = [];
});

const currentSupplier = computed(() => props.supplier
    ?? props.suppliers?.find((supplier) => supplier.uuid === form.supplier_uuid)
    ?? (props.supplierName ? { name: props.supplierName } : null));
const supplierOptions = computed(() => (props.suppliers ?? []).map((supplier) => ({ value: supplier.uuid, label: supplier.name })));

// --- Recherche avec suggestions ---------------------------------------------
// Le serveur dit quelles entrées sont le même produit (`product_group`) : un
// catalogue liste souvent un article sous deux références, et une commande
// n'accepte qu'une ligne par produit (ADR-098).
const chosenGroups = computed(() => new Set(form.lines.map((line) => line.product_group).filter(Boolean)));
const isChosen = (product) => chosenGroups.value.has(product.product_group);
const filterProducts = (needle) => {
    const query = needle.trim().toLowerCase();

    return props.medicines.filter((product) => !query || `${product.name ?? ''} ${product.code ?? ''}`.toLowerCase().includes(query));
};

const query = ref('');
const suggestOpen = ref(false);
const highlighted = ref(0);
const searchInput = ref(null);
const suggestions = computed(() => filterProducts(query.value).slice(0, 8));

watch(query, () => { highlighted.value = 0; suggestOpen.value = true; });

const moveHighlight = (delta) => {
    if (!suggestions.value.length) return;
    suggestOpen.value = true;
    highlighted.value = (highlighted.value + delta + suggestions.value.length) % suggestions.value.length;
};

const addProduct = async (product) => {
    if (!product || isChosen(product)) return;

    form.lines.push({
        product_key: productKey(product),
        product_group: product.product_group,
        name: product.name,
        code: product.code,
        is_new: product.in_clinic_catalog === false,
        quantity_ordered: 1,
        unit_price: product.quoted_price ?? '',
        // Le prix du fournisseur s'applique tout seul ; il ne se saisit que
        // s'il manque, ou si un prix a été négocié pour cette commande —
        // c'est celui-là qui est figé (ADR-097).
        supplier_price: product.quoted_price ?? null,
        editing_price: !product.quoted_price,
    });
    query.value = '';
    suggestOpen.value = false;
    await nextTick();
    // La quantité est ce qui reste à dire : le curseur y va directement.
    document.getElementById(`qty-${form.lines.length - 1}`)?.select();
};

const chooseHighlighted = () => {
    const product = suggestions.value[highlighted.value];
    if (product && !isChosen(product)) addProduct(product);
};

const closeSuggestions = () => setTimeout(() => { suggestOpen.value = false; }, 150);

// « Parcourir » : le catalogue entier, pour qui ne sait pas quoi chercher.
const browsing = ref(false);
const browseSearch = ref('');
const browseMatches = computed(() => filterProducts(browseSearch.value));
const browseGroups = computed(() => [
    { key: 'clinic', label: 'Déjà au catalogue de la clinique', items: browseMatches.value.filter((p) => p.in_clinic_catalog !== false) },
    { key: 'catalog', label: 'Au catalogue du fournisseur', items: browseMatches.value.filter((p) => p.in_clinic_catalog === false) },
]);

// --- Lignes -----------------------------------------------------------------
const removeLine = (index) => form.lines.splice(index, 1);
const step = (line, delta) => { line.quantity_ordered = Math.max(1, (Number(line.quantity_ordered) || 1) + delta); };
const lineTotal = (line) => (Number(line.quantity_ordered) || 0) * (Number(line.unit_price) || 0);
const total = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));
const units = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.quantity_ordered) || 0), 0));
const incomplete = computed(() => form.lines.filter((line) => !(Number(line.unit_price) > 0) || !(Number(line.quantity_ordered) >= 1)).length);
const newProducts = computed(() => form.lines.filter((line) => line.is_new).length);
const lineError = (index, field) => form.errors[`lines.${index}.${field}`];

const ready = computed(() => Boolean(form.supplier_uuid) && form.lines.length > 0 && incomplete.value === 0);
const blockReason = computed(() => {
    if (!form.supplier_uuid) return 'Choisissez d’abord le fournisseur.';
    if (!form.lines.length) return 'Ajoutez au moins un produit.';
    if (incomplete.value) return `${incomplete.value} ligne${incomplete.value > 1 ? 's' : ''} sans prix ou sans quantité.`;

    return '';
});

// --- Envoi ------------------------------------------------------------------
const confirmingSend = ref(false);
const save = (send) => {
    if (!ready.value) return;
    form.send = send;
    const transformed = form.transform(({ supplier_uuid, lines, ...data }) => ({
        ...data,
        lines: lines.map(({ product_key, product_group, supplier_price, editing_price, is_new, name, code, ...line }) => ({ ...line, ...splitKey(product_key) })),
    }));
    const options = { preserveScroll: true, onSuccess: () => { confirmingSend.value = false; } };
    if (props.order) transformed.put(props.submitUrl(form.supplier_uuid), options);
    else transformed.post(props.submitUrl(form.supplier_uuid), options);
};

const today = new Date().toISOString().slice(0, 10);
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
                <label class="col-span-2 block">
                    <span class="mb-1 block text-xs font-semibold text-muted-foreground">Livraison prévue le <span class="font-normal">(facultatif)</span></span>
                    <Input v-model="form.expected_delivery_at" type="date" :min="today" />
                    <span v-if="form.errors.expected_delivery_at" class="mt-1 block text-xs text-destructive">{{ form.errors.expected_delivery_at }}</span>
                </label>
            </dl>
        </section>

        <!-- Aucun fournisseur / rien à commander -->
        <section v-if="!form.supplier_uuid" class="rounded-xl border border-dashed border-border bg-muted/30 px-6 py-10 text-center">
            <Building2 class="mx-auto h-8 w-8 text-muted-foreground" />
            <p class="mt-3 font-semibold text-foreground">Choisissez d’abord le fournisseur</p>
            <p class="mt-1 text-sm text-muted-foreground">Ses produits s’afficheront ici : ceux que la clinique tient déjà de lui, et ceux de son catalogue.</p>
        </section>

        <section v-else-if="!medicines.length" class="rounded-xl border border-amber-200 bg-amber-50 px-6 py-8 text-center dark:border-amber-900 dark:bg-amber-950/30">
            <PackageOpen class="mx-auto h-8 w-8 text-amber-600" />
            <p class="mt-3 font-semibold text-amber-900 dark:text-amber-100">Aucun produit à commander chez ce fournisseur</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-amber-900/90 dark:text-amber-100/90">Son catalogue n’a pas encore été importé. Importez-le : ses produits deviendront commandables directement.</p>
            <Button v-if="catalogHref" :as="Link" :href="catalogHref" class="mt-4"><FileSpreadsheet class="h-4 w-4" />Ouvrir le catalogue du fournisseur</Button>
        </section>

        <!-- Produits -->
        <section v-else class="rounded-xl border border-border bg-card shadow-sm">
            <header class="flex flex-col gap-3 border-b border-border p-5 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="font-heading text-base font-bold text-foreground">Produits commandés</h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">Le prix d’achat vient du catalogue du fournisseur : vous n’indiquez que la quantité.</p>
                </div>
                <Button type="button" variant="outline" @click="browseSearch = ''; browsing = true"><ListPlus class="h-4 w-4" />Parcourir le catalogue</Button>
            </header>

            <!-- Recherche avec suggestions -->
            <div class="relative border-b border-border p-5">
                <label for="product-search" class="sr-only">Ajouter un produit</label>
                <IconInput
                    id="product-search"
                    ref="searchInput"
                    v-model="query"
                    :icon="Search"
                    autocomplete="off"
                    role="combobox"
                    :aria-expanded="suggestOpen && suggestions.length > 0"
                    aria-controls="product-suggestions"
                    placeholder="Rechercher un produit ou une référence pour l’ajouter…"
                    class="h-12"
                    @focus="suggestOpen = true"
                    @blur="closeSuggestions"
                    @keydown.down.prevent="moveHighlight(1)"
                    @keydown.up.prevent="moveHighlight(-1)"
                    @keydown.enter.prevent="chooseHighlighted"
                    @keydown.esc="suggestOpen = false"
                />
                <ul
                    v-if="suggestOpen && suggestions.length"
                    id="product-suggestions"
                    role="listbox"
                    class="absolute inset-x-5 top-[calc(100%-0.75rem)] z-30 max-h-80 overflow-y-auto rounded-xl border border-border bg-popover p-1.5 shadow-xl"
                >
                    <li
                        v-for="(product, index) in suggestions"
                        :key="productKey(product)"
                        role="option"
                        :aria-selected="index === highlighted"
                        :aria-disabled="isChosen(product)"
                        :class="['flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5', index === highlighted ? 'bg-accent' : '', isChosen(product) ? 'cursor-default opacity-60' : '']"
                        @mouseenter="highlighted = index"
                        @mousedown.prevent="addProduct(product)"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-foreground">{{ product.name }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                <span class="font-mono">{{ product.code }}</span>
                                <span v-if="product.in_clinic_catalog === false"> · nouveau au catalogue</span>
                            </p>
                        </div>
                        <span v-if="isChosen(product)" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"><Check class="h-3.5 w-3.5" />Déjà ajouté</span>
                        <span v-else class="shrink-0 text-sm font-semibold tabular-nums" :class="product.quoted_price ? 'text-foreground' : 'text-amber-600'">
                            {{ product.quoted_price ? formatMoney(product.quoted_price) : 'Prix à saisir' }}
                        </span>
                    </li>
                </ul>
                <p v-else-if="suggestOpen && query.trim() && !suggestions.length" class="absolute inset-x-5 top-[calc(100%-0.75rem)] z-30 rounded-xl border border-border bg-popover px-4 py-3 text-sm text-muted-foreground shadow-xl">
                    Aucun produit ne correspond à « {{ query }} ».
                </p>
            </div>

            <!-- Lignes -->
            <div v-if="form.lines.length" class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-muted/60 text-xs font-semibold text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 text-start">Produit</th>
                            <th class="w-44 px-4 py-3 text-center">Quantité</th>
                            <th class="w-48 px-4 py-3 text-end">Prix d’achat unitaire</th>
                            <th class="w-36 px-4 py-3 text-end">Total</th>
                            <th class="w-14 px-3 py-3"><span class="sr-only">Retirer</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="(line, index) in form.lines" :key="line.product_key" class="align-top">
                            <td class="px-5 py-3.5">
                                <p class="font-semibold text-foreground">{{ line.name }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <span class="font-mono">{{ line.code }}</span>
                                    <Badge v-if="line.is_new" variant="secondary" class="px-2 py-0.5">Nouveau au catalogue</Badge>
                                </p>
                                <p v-if="lineError(index, 'medicine_uuid') || lineError(index, 'supplier_catalog_item_uuid')" class="mt-1 text-xs text-destructive">{{ lineError(index, 'medicine_uuid') || lineError(index, 'supplier_catalog_item_uuid') }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <div :class="['mx-auto flex h-10 w-36 overflow-hidden rounded-lg border bg-card', lineError(index, 'quantity_ordered') ? 'border-destructive' : 'border-border']">
                                    <button type="button" class="w-10 text-lg text-muted-foreground hover:bg-muted" :aria-label="`Diminuer la quantité de ${line.name}`" @click="step(line, -1)">−</button>
                                    <input :id="`qty-${index}`" v-model.number="line.quantity_ordered" type="number" min="1" inputmode="numeric" class="w-full min-w-0 border-0 bg-transparent text-center text-sm font-semibold text-foreground outline-none" :aria-label="`Quantité de ${line.name}`">
                                    <button type="button" class="w-10 text-lg text-muted-foreground hover:bg-muted" :aria-label="`Augmenter la quantité de ${line.name}`" @click="step(line, 1)">+</button>
                                </div>
                                <p v-if="lineError(index, 'quantity_ordered')" class="mt-1 text-center text-xs text-destructive">La quantité doit être d’au moins 1.</p>
                            </td>
                            <td class="px-4 py-3.5 text-end">
                                <template v-if="!line.editing_price">
                                    <p class="h-10 font-semibold leading-10 tabular-nums text-foreground">{{ formatMoney(line.unit_price) }}</p>
                                    <button type="button" class="text-xs font-semibold text-primary hover:underline" @click="line.editing_price = true">Changer le prix</button>
                                </template>
                                <template v-else>
                                    <div class="relative ms-auto w-40">
                                        <Input v-model="line.unit_price" type="number" min="0.01" step="0.01" inputmode="decimal" :class="['pe-12 text-end', !(Number(line.unit_price) > 0) || lineError(index, 'unit_price') ? 'border-amber-400' : '']" :aria-label="`Prix d’achat unitaire de ${line.name}`" />
                                        <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">MGA</span>
                                    </div>
                                    <p v-if="!line.supplier_price" class="mt-1 text-xs text-amber-600">Pas de prix au catalogue : indiquez-le.</p>
                                    <p v-else-if="String(line.unit_price) !== String(line.supplier_price)" class="mt-1 text-xs text-amber-600">Prix négocié · catalogue : {{ formatMoney(line.supplier_price) }}</p>
                                </template>
                            </td>
                            <td class="px-4 py-3.5 text-end text-base font-bold leading-10 tabular-nums text-foreground">{{ formatMoney(lineTotal(line)) }}</td>
                            <td class="px-3 py-3.5">
                                <Button type="button" variant="ghost" size="icon" class="hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/30" :aria-label="`Retirer ${line.name}`" @click="removeLine(index)"><Trash2 class="h-4 w-4" /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="px-6 py-12 text-center">
                <PackageOpen class="mx-auto h-9 w-9 text-muted-foreground" />
                <p class="mt-3 font-semibold text-foreground">Aucun produit ajouté</p>
                <p class="mt-1 text-sm text-muted-foreground">Tapez le nom d’un produit dans la recherche ci-dessus, ou parcourez le catalogue du fournisseur.</p>
            </div>

            <div class="border-t border-border p-5">
                <label class="block max-w-2xl">
                    <span class="mb-1.5 block text-sm font-medium text-foreground">Remarque pour le fournisseur <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                    <Input v-model="form.notes" maxlength="2000" placeholder="Ex. livraison urgente, appeler avant de passer" />
                </label>
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
                        {{ form.lines.length }} ligne{{ form.lines.length > 1 ? 's' : '' }} · {{ units }} unité{{ units > 1 ? 's' : '' }}
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
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Produits</dt><dd class="font-semibold tabular-nums text-foreground">{{ form.lines.length }} ligne{{ form.lines.length > 1 ? 's' : '' }} · {{ units }} unité{{ units > 1 ? 's' : '' }}</dd></div>
                <div v-if="newProducts" class="flex justify-between gap-3"><dt class="text-muted-foreground">Nouveaux au catalogue</dt><dd class="font-semibold tabular-nums text-foreground">{{ newProducts }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-border pt-2"><dt class="text-muted-foreground">Montant total</dt><dd class="text-base font-bold tabular-nums text-foreground">{{ formatMoney(total) }}</dd></div>
            </dl>
            <p class="mt-3 text-sm text-muted-foreground">Une fois envoyée, la commande ne peut plus être modifiée : seule une annulation motivée reste possible.</p>
        </ConfirmModal>

        <Dialog
            :open="browsing"
            size="lg"
            title="Catalogue du fournisseur"
            :description="`Tout ce que ${currentSupplier?.name || 'ce fournisseur'} propose.`"
            body-class="max-h-[60vh] overflow-y-auto"
            @update:open="browsing = $event"
        >
            <IconInput v-model="browseSearch" :icon="Search" type="search" autofocus placeholder="Filtrer…" class="mb-4" />
            <div class="space-y-5">
                <section v-for="group in browseGroups" :key="group.key">
                    <template v-if="group.items.length">
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-muted-foreground">{{ group.label }} ({{ group.items.length }})</p>
                        <ul class="space-y-1.5">
                            <li v-for="product in group.items" :key="productKey(product)" class="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-foreground">{{ product.name }}</p>
                                    <p class="truncate text-xs text-muted-foreground">
                                        <span class="font-mono">{{ product.code }}</span>
                                        · {{ product.quoted_price ? formatMoney(product.quoted_price) : 'prix à saisir' }}
                                    </p>
                                </div>
                                <span v-if="isChosen(product)" class="flex shrink-0 items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300"><Check class="h-4 w-4" />Ajouté</span>
                                <Button v-else type="button" size="sm" variant="outline" class="shrink-0" @click="addProduct(product)"><Plus class="h-4 w-4" />Ajouter</Button>
                            </li>
                        </ul>
                    </template>
                </section>
                <p v-if="!browseMatches.length" class="py-6 text-center text-sm text-muted-foreground">Aucun produit ne correspond à « {{ browseSearch }} ».</p>
            </div>
            <template #footer>
                <Button type="button" @click="browsing = false">Terminer</Button>
            </template>
        </Dialog>
    </form>
</template>
