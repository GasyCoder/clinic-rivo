<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';

const props = defineProps({
    visible: { type: Boolean, default: false },
    form: { type: Object, required: true },
    medicines: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'submit']);

const search = ref('');
const category = ref('ALL');
const catalogView = ref('grid');
const mobileStep = ref('catalog');
const catalogPage = ref(1);
const cartPage = ref(1);
const CATALOG_PAGE_SIZE = 6;
const CART_PAGE_SIZE = 4;

const categories = computed(() => [...new Set(props.medicines
    .map((medicine) => medicine.category?.name)
    .filter(Boolean))]
    .sort((left, right) => left.localeCompare(right, 'fr')));

const filteredMedicines = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return props.medicines.filter((medicine) => {
        const matchesCategory = category.value === 'ALL' || medicine.category?.name === category.value;
        const matchesSearch = !needle || [
            medicine.name,
            medicine.generic_name,
            medicine.code,
            medicine.barcode,
            medicine.form_label,
            medicine.strength,
        ].filter(Boolean).some((value) => String(value).toLocaleLowerCase().includes(needle));

        return matchesCategory && matchesSearch;
    });
});
const catalogPageCount = computed(() => Math.max(1, Math.ceil(filteredMedicines.value.length / CATALOG_PAGE_SIZE)));
const paginatedMedicines = computed(() => {
    const start = (catalogPage.value - 1) * CATALOG_PAGE_SIZE;
    return filteredMedicines.value.slice(start, start + CATALOG_PAGE_SIZE);
});

const medicineByUuid = computed(() => new Map(props.medicines.map((medicine) => [medicine.uuid, medicine])));
const cart = computed(() => props.form.lines.map((line) => ({
    line,
    medicine: medicineByUuid.value.get(line.medicine_uuid),
})).filter((item) => item.medicine));
const cartPageCount = computed(() => Math.max(1, Math.ceil(cart.value.length / CART_PAGE_SIZE)));
const paginatedCart = computed(() => {
    const start = (cartPage.value - 1) * CART_PAGE_SIZE;
    return cart.value.slice(start, start + CART_PAGE_SIZE);
});
const cartQuantity = computed(() => cart.value.reduce((total, item) => total + Number(item.line.quantity || 0), 0));
const cartTotal = computed(() => cart.value.reduce(
    (total, item) => total + (Number(item.medicine.sale_price || 0) * Number(item.line.quantity || 0)),
    0,
));
const requiresPrescription = computed(() => cart.value.some((item) => item.medicine.prescription_required));
const prescriptionMissing = computed(() => requiresPrescription.value
    && !String(props.form.external_prescription_reference || '').trim());
const quantitiesAreValid = computed(() => cart.value.every((item) => {
    const quantity = Number(item.line.quantity || 0);
    return Number.isInteger(quantity) && quantity >= 1 && quantity <= Number(item.medicine.available_quantity);
}));
const canSubmit = computed(() => cart.value.length > 0
    && quantitiesAreValid.value
    && !prescriptionMissing.value
    && !props.form.processing);

watch([search, category], () => {
    catalogPage.value = 1;
});
watch(catalogPageCount, (pageCount) => {
    catalogPage.value = Math.min(catalogPage.value, pageCount);
});
watch(cartPageCount, (pageCount) => {
    cartPage.value = Math.min(cartPage.value, pageCount);
});

const money = (value) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(Number(value || 0))} MGA`;
const number = (value) => new Intl.NumberFormat('fr-FR').format(Number(value || 0));

const lineFor = (medicine) => props.form.lines.find((line) => line.medicine_uuid === medicine.uuid);

const addMedicine = (medicine) => {
    const existing = lineFor(medicine);

    if (existing) {
        existing.quantity = Math.min(Number(existing.quantity || 0) + 1, Number(medicine.available_quantity));
        return;
    }

    props.form.lines.push({ medicine_uuid: medicine.uuid, quantity: 1 });
    cartPage.value = Math.ceil(props.form.lines.length / CART_PAGE_SIZE);
};

const changeQuantity = (item, delta) => {
    item.line.quantity = Math.min(
        Number(item.medicine.available_quantity),
        Math.max(1, Number(item.line.quantity || 1) + delta),
    );
};

const normalizeQuantity = (item) => {
    const quantity = Math.trunc(Number(item.line.quantity || 1));
    item.line.quantity = Math.min(Number(item.medicine.available_quantity), Math.max(1, quantity));
};

const removeLine = (uuid) => {
    props.form.lines = props.form.lines.filter((line) => line.medicine_uuid !== uuid);
};

const focusInvalidField = (key) => document.querySelector(`[name="${CSS.escape(key)}"]`)?.focus();
</script>

<template>
    <section
        v-if="visible"
        id="external-sale-workspace"
        class="scroll-mt-5"
        role="region"
        aria-labelledby="external-sale-title"
    >
        <form
            class="flex w-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-sm dark:border-gray-900 dark:bg-gray-1000"
            @submit.prevent="emit('submit')"
        >
                <header class="flex shrink-0 items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 py-4 dark:border-gray-900 dark:bg-gray-950 sm:px-6">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-xl text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300">
                            <Icon name="shopping-cart" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-relaxed text-emerald-600">Parcours client externe</p>
                            <h2 id="external-sale-title" class="truncate font-heading text-lg font-bold text-slate-700 dark:text-white sm:text-xl">Nouvelle demande comptoir</h2>
                            <p class="hidden text-xs text-slate-500 sm:block">Sélection, réservation FEFO, puis transmission automatique à la Caisse.</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="hidden items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-[11px] font-bold text-amber-700 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300 md:inline-flex">
                            <Icon name="shield-check" /> Aucun encaissement ici
                        </span>
                        <Button variant="white-outline" size="sm" type="button" @click="emit('close')">
                            <Icon name="arrow-left" /><span class="ms-1.5 hidden sm:inline">Retour aux demandes</span>
                        </Button>
                    </div>
                </header>

                <ValidationErrorSummary class="mx-4 mt-3 shrink-0 sm:mx-6" :errors="form.errors" @select="focusInvalidField" />

                <nav class="grid shrink-0 grid-cols-2 gap-1 border-b border-gray-200 bg-white p-2 dark:border-gray-900 dark:bg-gray-950 lg:hidden" aria-label="Étapes de la demande externe">
                    <button type="button" :class="['flex h-10 items-center justify-center gap-2 rounded-lg text-xs font-bold transition', mobileStep === 'catalog' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300']" @click="mobileStep = 'catalog'"><Icon name="capsule" /> Catalogue</button>
                    <button type="button" :class="['flex h-10 items-center justify-center gap-2 rounded-lg text-xs font-bold transition', mobileStep === 'cart' ? 'bg-slate-700 text-white shadow-sm' : 'bg-gray-100 text-slate-500 dark:bg-gray-900 dark:text-slate-300']" @click="mobileStep = 'cart'"><Icon name="shopping-cart" /> Panier <span :class="['rounded-full px-1.5 py-0.5 text-[10px]', mobileStep === 'cart' ? 'bg-white/20' : 'bg-white dark:bg-gray-950']">{{ cartQuantity }}</span></button>
                </nav>

                <div class="grid lg:grid-cols-[minmax(0,1fr)_480px]">
                    <section :class="[mobileStep === 'catalog' ? 'flex' : 'hidden', 'min-h-0 flex-col border-b border-gray-200 dark:border-gray-900 lg:flex lg:border-b-0 lg:border-e']">
                        <div class="shrink-0 space-y-3 bg-white px-4 py-4 dark:bg-gray-950 sm:px-6">
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <label class="relative block flex-1">
                                    <span class="sr-only">Rechercher un médicament</span>
                                    <Icon class="pointer-events-none absolute inset-y-0 start-3 my-auto text-lg text-slate-400" name="search" />
                                    <input
                                        v-model="search"
                                        type="search"
                                        class="h-11 w-full rounded-lg border border-gray-200 bg-gray-50 ps-10 pe-3 text-sm text-slate-700 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100 dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                        placeholder="Nom, DCI, code ou code-barres…"
                                    >
                                </label>
                                <select v-model="category" class="h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-600 outline-none focus:border-emerald-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 sm:w-56">
                                    <option value="ALL">Toutes les catégories</option>
                                    <option v-for="item in categories" :key="item" :value="item">{{ item }}</option>
                                </select>
                            </div>
                            <div class="flex items-center justify-between gap-3 text-xs text-slate-400">
                                <span>{{ filteredMedicines.length }} produit(s) disponible(s)</span>
                                <div class="flex items-center gap-3">
                                    <span class="hidden md:inline">Cliquer pour ajouter au panier</span>
                                    <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-800 dark:bg-gray-900" role="group" aria-label="Affichage du catalogue">
                                        <button
                                            type="button"
                                            :class="['inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 font-bold transition', catalogView === 'grid' ? 'bg-white text-emerald-600 shadow-sm dark:bg-gray-950 dark:text-emerald-300' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']"
                                            :aria-pressed="catalogView === 'grid'"
                                            @click="catalogView = 'grid'"
                                        >
                                            <Icon name="grid" /> <span class="hidden sm:inline">Grille</span>
                                        </button>
                                        <button
                                            type="button"
                                            :class="['inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 font-bold transition', catalogView === 'list' ? 'bg-white text-emerald-600 shadow-sm dark:bg-gray-950 dark:text-emerald-300' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']"
                                            :aria-pressed="catalogView === 'list'"
                                            @click="catalogView = 'list'"
                                        >
                                            <Icon name="list" /> <span class="hidden sm:inline">Liste</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="min-h-[280px] flex-1 overflow-y-auto p-4 sm:p-6">
                            <div :class="catalogView === 'grid' ? 'grid gap-3 sm:grid-cols-2 xl:grid-cols-3' : 'grid gap-2 sm:grid-cols-2'">
                                <button
                                    v-for="medicine in paginatedMedicines"
                                    :key="medicine.uuid"
                                    type="button"
                                    :class="[
                                        'group relative w-full rounded-xl border bg-white text-start shadow-sm transition hover:border-emerald-300 hover:shadow-md dark:bg-gray-950',
                                        catalogView === 'grid' ? 'min-h-32 p-3 hover:-translate-y-0.5' : 'flex items-center gap-3 p-3',
                                        lineFor(medicine) ? 'border-emerald-400 ring-2 ring-emerald-100 dark:ring-emerald-950' : 'border-gray-200 dark:border-gray-800',
                                    ]"
                                    @click="addMedicine(medicine)"
                                >
                                    <template v-if="catalogView === 'grid'">
                                        <div class="flex items-start justify-between gap-3">
                                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300"><Icon name="capsule" /></span>
                                            <span v-if="lineFor(medicine)" class="rounded-full bg-emerald-600 px-2 py-1 text-[10px] font-bold text-white">{{ lineFor(medicine).quantity }} au panier</span>
                                            <span v-else class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-slate-400 transition group-hover:bg-emerald-600 group-hover:text-white dark:bg-gray-900"><Icon name="plus" /></span>
                                        </div>
                                        <p class="mt-2.5 line-clamp-2 text-sm font-bold text-slate-700 dark:text-white">{{ medicine.name }}</p>
                                        <p class="mt-1 line-clamp-1 text-[10px] text-slate-400">{{ medicine.code }}<span v-if="medicine.generic_name"> · {{ medicine.generic_name }}</span><span v-if="medicine.strength"> · {{ medicine.strength }}</span></p>
                                        <div class="mt-3 flex items-end justify-between gap-2">
                                            <div>
                                                <p class="text-sm font-bold text-slate-700 dark:text-white">{{ money(medicine.sale_price) }}</p>
                                                <p class="mt-0.5 text-[10px] font-medium text-emerald-600">{{ number(medicine.available_quantity) }} {{ medicine.unit }} disponible(s)</p>
                                            </div>
                                            <span v-if="medicine.prescription_required" class="rounded border border-amber-200 bg-amber-50 px-1.5 py-1 text-[9px] font-bold uppercase text-amber-700 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">Ordonnance</span>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300"><Icon name="capsule" /></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-2">
                                                <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ medicine.name }}</p>
                                                <p class="shrink-0 text-xs font-bold text-slate-700 dark:text-white">{{ money(medicine.sale_price) }}</p>
                                            </div>
                                            <p class="mt-0.5 truncate text-[10px] text-slate-400">{{ medicine.code }}<span v-if="medicine.generic_name"> · {{ medicine.generic_name }}</span><span v-if="medicine.strength"> · {{ medicine.strength }}</span></p>
                                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                                <p class="text-[10px] font-medium text-emerald-600">{{ number(medicine.available_quantity) }} {{ medicine.unit }}</p>
                                                <span v-if="medicine.prescription_required" class="rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[8px] font-bold uppercase text-amber-700 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300">Ordonnance</span>
                                            </div>
                                        </div>
                                        <span v-if="lineFor(medicine)" class="shrink-0 rounded-full bg-emerald-600 px-2 py-1 text-[10px] font-bold text-white">{{ lineFor(medicine).quantity }}</span>
                                        <span v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-slate-400 transition group-hover:bg-emerald-600 group-hover:text-white dark:bg-gray-900"><Icon name="plus" /></span>
                                    </template>
                                </button>
                            </div>

                            <div v-if="!filteredMedicines.length" class="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white px-6 text-center dark:border-gray-800 dark:bg-gray-950">
                                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-xl text-slate-400 dark:bg-gray-900"><Icon name="search" /></span>
                                <p class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Aucun médicament disponible</p>
                                <p class="mt-1 max-w-sm text-xs leading-5 text-slate-400">Modifiez la recherche ou vérifiez le stock et le tarif actif.</p>
                            </div>

                            <nav v-if="catalogPageCount > 1" class="mt-5 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-800 dark:bg-gray-950" aria-label="Pagination du catalogue">
                                <button type="button" class="rounded border border-gray-200 px-3 py-1.5 font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:text-slate-300" :disabled="catalogPage === 1" @click="catalogPage--">Précédent</button>
                                <span class="text-slate-400">Page <strong class="text-slate-700 dark:text-white">{{ catalogPage }}</strong> sur {{ catalogPageCount }}</span>
                                <button type="button" class="rounded border border-gray-200 px-3 py-1.5 font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:text-slate-300" :disabled="catalogPage === catalogPageCount" @click="catalogPage++">Suivant</button>
                            </nav>
                        </div>
                    </section>

                    <aside :class="[mobileStep === 'cart' ? 'flex' : 'hidden', 'min-h-0 flex-col bg-white dark:bg-gray-950 lg:flex']">
                        <div class="shrink-0 border-b border-gray-200 p-4 dark:border-gray-900 sm:p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Demande externe</p>
                                    <h3 class="mt-0.5 text-base font-bold text-slate-700 dark:text-white">Panier · {{ cartQuantity }} article(s)</h3>
                                </div>
                                <span class="rounded-lg bg-slate-700 px-3 py-2 text-sm font-bold text-white">{{ money(cartTotal) }}</span>
                            </div>

                            <label class="mt-4 block text-xs font-medium text-slate-600 dark:text-slate-300">
                                Référence ordonnance externe <span v-if="requiresPrescription" class="text-red-500">*</span>
                                <input v-model="form.external_prescription_reference" name="external_prescription_reference" maxlength="255" :class="['mt-1 h-10 w-full rounded border bg-white px-3 text-sm dark:bg-gray-950 dark:text-white', prescriptionMissing ? 'border-amber-400 focus:border-amber-500' : 'border-gray-200 dark:border-gray-800']" placeholder="Numéro ou référence vérifiable">
                            </label>

                            <details class="group mt-3 rounded-lg border border-gray-200 bg-gray-50 open:bg-white dark:border-gray-800 dark:bg-gray-900 dark:open:bg-gray-950">
                                <summary class="flex cursor-pointer list-none flex-col gap-2 px-3 py-2.5 text-xs font-bold text-slate-600 dark:text-slate-300 sm:flex-row sm:items-center sm:justify-between">
                                    <span class="inline-flex items-center gap-2"><Icon name="user" /> Client et prescripteur <span class="font-normal text-slate-400">(facultatifs)</span></span>
                                    <span class="inline-flex items-center gap-2 self-end sm:self-auto">
                                        <span class="rounded-full bg-gray-200 px-2 py-1 text-[10px] font-bold text-slate-500 dark:bg-gray-800 dark:text-slate-300">Informations facultatives</span>
                                        <span class="text-[10px] uppercase tracking-wide text-primary-600">Ouvrir</span>
                                        <Icon class="text-sm text-slate-400 transition-transform group-open:rotate-180" name="chevron-down" />
                                    </span>
                                </summary>
                                <div class="grid gap-3 border-t border-gray-200 p-3 dark:border-gray-800">
                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Nom du client
                                        <input v-model="form.customer_name" name="customer_name" maxlength="255" class="mt-1 h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Client comptoir">
                                    </label>
                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Téléphone
                                        <input v-model="form.customer_phone" name="customer_phone" maxlength="50" class="mt-1 h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Facultatif">
                                    </label>
                                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Prescripteur externe
                                        <input v-model="form.external_prescriber" name="external_prescriber" maxlength="255" class="mt-1 h-10 w-full rounded border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Facultatif">
                                    </label>
                                </div>
                            </details>
                        </div>

                        <div class="min-h-[220px] flex-1 overflow-y-auto p-4 sm:p-5">
                            <div v-if="cart.length" class="space-y-3">
                                <article v-for="item in paginatedCart" :key="item.medicine.uuid" class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-700 dark:text-white">{{ item.medicine.name }}</p>
                                            <p class="mt-0.5 text-[11px] text-slate-400">{{ money(item.medicine.sale_price) }} / {{ item.medicine.unit }}</p>
                                        </div>
                                        <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20" :aria-label="`Retirer ${item.medicine.name}`" @click="removeLine(item.medicine.uuid)"><Icon name="trash" /></button>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" @click="changeQuantity(item, -1)">−</button>
                                        <input v-model.number="item.line.quantity" :name="`lines.${form.lines.indexOf(item.line)}.quantity`" type="number" min="1" :max="item.medicine.available_quantity" class="h-9 w-16 rounded-lg border border-gray-200 bg-white px-2 text-center text-sm font-bold dark:border-gray-800 dark:bg-gray-950 dark:text-white" @change="normalizeQuantity(item)">
                                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" @click="changeQuantity(item, 1)">+</button>
                                        <span class="ms-auto text-sm font-bold text-slate-700 dark:text-white">{{ money(Number(item.medicine.sale_price) * Number(item.line.quantity)) }}</span>
                                    </div>
                                    <input :name="`lines.${form.lines.indexOf(item.line)}.medicine_uuid`" :value="item.medicine.uuid" type="hidden">
                                </article>

                                <nav v-if="cartPageCount > 1" class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs dark:border-gray-800 dark:bg-gray-900" aria-label="Pagination du panier">
                                    <button type="button" class="rounded border border-gray-200 bg-white px-2.5 py-1.5 font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" :disabled="cartPage === 1" @click="cartPage--">Préc.</button>
                                    <span class="text-slate-400">Page <strong class="text-slate-700 dark:text-white">{{ cartPage }}</strong> / {{ cartPageCount }}</span>
                                    <button type="button" class="rounded border border-gray-200 bg-white px-2.5 py-1.5 font-bold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" :disabled="cartPage === cartPageCount" @click="cartPage++">Suiv.</button>
                                </nav>
                            </div>

                            <div v-else class="flex h-full min-h-52 flex-col items-center justify-center text-center">
                                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-2xl text-slate-300 dark:bg-gray-900"><Icon name="cart" /></span>
                                <p class="mt-3 text-sm font-bold text-slate-700 dark:text-white">Le panier est vide</p>
                                <p class="mt-1 max-w-xs text-xs leading-5 text-slate-400">Choisissez les produits dans le catalogue. Le stock disponible est déjà calculé hors réservations.</p>
                            </div>
                        </div>

                        <footer class="shrink-0 border-t border-gray-200 bg-gray-50 p-4 dark:border-gray-900 dark:bg-gray-1000 sm:p-5">
                            <div v-if="prescriptionMissing" class="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                                <Icon class="mt-0.5 shrink-0" name="alert-triangle" />
                                <span>Une référence d’ordonnance est obligatoire pour au moins un produit du panier.</span>
                            </div>
                            <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5 text-xs leading-5 text-sky-800 dark:border-sky-900 dark:bg-sky-950/20 dark:text-sky-200">
                                <strong>Étape suivante :</strong> la facture sera visible à la Caisse. La délivrance restera bloquée jusqu’au règlement ou à la prise en charge intégrale.
                            </div>
                            <div class="grid gap-2 xl:grid-cols-[auto_minmax(0,1fr)]">
                                <Button class="justify-center" variant="white-outline" size="rg" type="button" @click="emit('close')">Retour aux demandes</Button>
                                <Button class="flex-1 justify-center" size="rg" type="submit" :disabled="!canSubmit">
                                    <Icon name="file-text" /><span class="ms-2">{{ form.processing ? 'Transmission…' : 'Créer et transmettre à la Caisse' }}</span>
                                </Button>
                            </div>
                        </footer>
                    </aside>
                </div>
        </form>
    </section>
</template>
