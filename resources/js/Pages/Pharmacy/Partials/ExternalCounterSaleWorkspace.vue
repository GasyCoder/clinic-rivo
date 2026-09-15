<script setup>
import { computed, ref, watch } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatMoney as money, formatNumber as number } from '@/utilities/pharmacyStatus';

const props = defineProps({
    visible: { type: Boolean, default: false },
    form: { type: Object, required: true },
    medicines: { type: Array, default: () => [] },
    canPrintTicket: { type: Boolean, default: false },
});

const emit = defineEmits(['submit']);

const search = ref('');
const category = ref('ALL');
const catalogView = ref('grid');
const mobileStep = ref('catalog');
const catalogPage = ref(1);
const cartPage = ref(1);
const showCreateConfirmation = ref(false);
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
const quantitiesAreValid = computed(() => cart.value.every((item) => {
    const quantity = Number(item.line.quantity || 0);
    return Number.isInteger(quantity) && quantity >= 1 && quantity <= Number(item.medicine.available_quantity);
}));
const canSubmit = computed(() => cart.value.length > 0
    && quantitiesAreValid.value
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

const requestCreation = () => {
    if (canSubmit.value) showCreateConfirmation.value = true;
};

const confirmCreation = () => {
    showCreateConfirmation.value = false;
    emit('submit');
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
            @submit.prevent="requestCreation"
        >
            <header class="flex shrink-0 items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 py-4 dark:border-gray-900 dark:bg-gray-950 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-xl text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300">
                        <Icon name="cart" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="external-sale-title" class="truncate font-heading text-xl font-bold text-slate-800 dark:text-white sm:text-2xl">Vente comptoir</h2>
                        <p class="hidden text-sm text-slate-500 sm:block">Ajoutez les médicaments au panier, puis transmettez le ticket à la Caisse.</p>
                    </div>
                </div>
                <span class="hidden items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-300 md:inline-flex">
                    <Icon name="shield-check" />Le client paie à la Caisse
                </span>
            </header>

            <ValidationErrorSummary class="mx-4 mt-3 shrink-0 sm:mx-6" :errors="form.errors" @select="focusInvalidField" />

            <nav class="grid shrink-0 grid-cols-2 gap-1 border-b border-gray-200 bg-white p-2 dark:border-gray-900 dark:bg-gray-950 lg:hidden" aria-label="Étapes de la vente">
                <button type="button" :class="['flex h-11 items-center justify-center gap-2 rounded-lg text-sm font-semibold transition', mobileStep === 'catalog' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300']" @click="mobileStep = 'catalog'"><Icon name="capsule" />Médicaments</button>
                <button type="button" :class="['flex h-11 items-center justify-center gap-2 rounded-lg text-sm font-semibold transition', mobileStep === 'cart' ? 'bg-slate-700 text-white shadow-sm' : 'bg-gray-100 text-slate-600 dark:bg-gray-900 dark:text-slate-300']" @click="mobileStep = 'cart'"><Icon name="cart" />Panier <span :class="['rounded-full px-1.5 text-xs', mobileStep === 'cart' ? 'bg-white/20' : 'bg-white dark:bg-gray-950']">{{ cartQuantity }}</span></button>
            </nav>

            <div class="grid lg:grid-cols-[minmax(0,1fr)_460px]">
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
                            <select v-model="category" class="h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-600 outline-none focus:border-emerald-500 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300 sm:w-56" aria-label="Filtrer par famille">
                                <option value="ALL">Toutes les familles</option>
                                <option v-for="item in categories" :key="item" :value="item">{{ item }}</option>
                            </select>
                        </div>
                        <div class="flex items-center justify-between gap-3 text-sm text-slate-500">
                            <span>{{ filteredMedicines.length }} médicament{{ filteredMedicines.length > 1 ? 's' : '' }} disponible{{ filteredMedicines.length > 1 ? 's' : '' }}</span>
                            <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-800 dark:bg-gray-900" role="group" aria-label="Affichage">
                                <button
                                    type="button"
                                    :class="['inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 font-semibold transition', catalogView === 'grid' ? 'bg-white text-emerald-600 shadow-sm dark:bg-gray-950 dark:text-emerald-300' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']"
                                    :aria-pressed="catalogView === 'grid'"
                                    @click="catalogView = 'grid'"
                                >
                                    <Icon name="grid" /><span class="hidden sm:inline">Vignettes</span>
                                </button>
                                <button
                                    type="button"
                                    :class="['inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 font-semibold transition', catalogView === 'list' ? 'bg-white text-emerald-600 shadow-sm dark:bg-gray-950 dark:text-emerald-300' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200']"
                                    :aria-pressed="catalogView === 'list'"
                                    @click="catalogView = 'list'"
                                >
                                    <Icon name="list" /><span class="hidden sm:inline">Liste</span>
                                </button>
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
                                    catalogView === 'grid' ? 'min-h-36 p-4' : 'flex items-center gap-3 p-3',
                                    lineFor(medicine) ? 'border-emerald-400 ring-2 ring-emerald-100 dark:ring-emerald-950' : 'border-gray-200 dark:border-gray-800',
                                ]"
                                :aria-label="`Ajouter ${medicine.name} au panier`"
                                @click="addMedicine(medicine)"
                            >
                                <template v-if="catalogView === 'grid'">
                                    <div class="flex items-start justify-between gap-3">
                                        <Badge v-if="medicine.prescription_required" tone="warning">Sur ordonnance</Badge>
                                        <span v-else />
                                        <span v-if="lineFor(medicine)" class="rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-bold text-white">{{ lineFor(medicine).quantity }} au panier</span>
                                        <span v-else class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-slate-400 transition group-hover:bg-emerald-600 group-hover:text-white dark:bg-gray-900"><Icon name="plus" /></span>
                                    </div>
                                    <p class="mt-2 line-clamp-2 text-base font-semibold text-slate-800 dark:text-white">{{ medicine.name }}</p>
                                    <p class="mt-0.5 line-clamp-1 text-xs text-slate-400">{{ medicine.form_label }}<span v-if="medicine.strength"> · {{ medicine.strength }}</span></p>
                                    <div class="mt-3 flex items-end justify-between gap-2">
                                        <p class="text-base font-bold text-slate-800 dark:text-white">{{ money(medicine.sale_price) }}</p>
                                        <p class="text-xs text-emerald-600">{{ number(medicine.available_quantity) }} {{ medicine.unit }} en stock</p>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="truncate text-sm font-semibold text-slate-800 dark:text-white">{{ medicine.name }}</p>
                                            <p class="shrink-0 text-sm font-bold text-slate-800 dark:text-white">{{ money(medicine.sale_price) }}</p>
                                        </div>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                            <span class="text-emerald-600">{{ number(medicine.available_quantity) }} {{ medicine.unit }} en stock</span>
                                            <Badge v-if="medicine.prescription_required" tone="warning">Sur ordonnance</Badge>
                                        </div>
                                    </div>
                                    <span v-if="lineFor(medicine)" class="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-bold text-white">{{ lineFor(medicine).quantity }}</span>
                                    <span v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-slate-400 transition group-hover:bg-emerald-600 group-hover:text-white dark:bg-gray-900"><Icon name="plus" /></span>
                                </template>
                            </button>
                        </div>

                        <div v-if="!filteredMedicines.length" class="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white px-6 text-center dark:border-gray-800 dark:bg-gray-950">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-xl text-slate-400 dark:bg-gray-900"><Icon name="search" /></span>
                            <p class="mt-3 text-sm font-semibold text-slate-800 dark:text-white">Aucun médicament trouvé</p>
                            <p class="mt-1 max-w-sm text-sm text-slate-500">Seuls les médicaments en stock et ayant un prix de vente apparaissent ici.</p>
                        </div>

                        <nav v-if="catalogPageCount > 1" class="mt-5 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950" aria-label="Pages de médicaments">
                            <button type="button" class="rounded-lg border border-gray-200 px-3 py-2 font-semibold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:text-slate-300" :disabled="catalogPage === 1" @click="catalogPage--">Précédent</button>
                            <span class="text-slate-500">Page <strong class="text-slate-800 dark:text-white">{{ catalogPage }}</strong> sur {{ catalogPageCount }}</span>
                            <button type="button" class="rounded-lg border border-gray-200 px-3 py-2 font-semibold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:text-slate-300" :disabled="catalogPage === catalogPageCount" @click="catalogPage++">Suivant</button>
                        </nav>
                    </div>
                </section>

                <aside :class="[mobileStep === 'cart' ? 'flex' : 'hidden', 'min-h-0 flex-col bg-white dark:bg-gray-950 lg:flex']">
                    <div class="shrink-0 border-b border-gray-200 p-4 dark:border-gray-900 sm:p-5">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-slate-800 dark:text-white">Panier · {{ cartQuantity }} article{{ cartQuantity > 1 ? 's' : '' }}</h3>
                            <span class="rounded-lg bg-slate-700 px-3 py-2 text-base font-bold text-white">{{ money(cartTotal) }}</span>
                        </div>

                        <details class="group mt-4 rounded-lg border border-gray-200 bg-gray-50 open:bg-white dark:border-gray-800 dark:bg-gray-900 dark:open:bg-gray-950">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-3 text-sm font-semibold text-slate-600 dark:text-slate-300">
                                <span class="inline-flex items-center gap-2"><Icon name="user" />Nom du client <span class="font-normal text-slate-400">(facultatif)</span></span>
                                <Icon class="text-sm text-slate-400 transition-transform group-open:rotate-180" name="chevron-down" />
                            </summary>
                            <div class="grid gap-3 border-t border-gray-200 p-3 dark:border-gray-800">
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Nom du client
                                    <input v-model="form.customer_name" name="customer_name" maxlength="255" class="mt-1 h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Client comptoir">
                                </label>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Téléphone
                                    <input v-model="form.customer_phone" name="customer_phone" maxlength="50" type="tel" class="mt-1 h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                                </label>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Médecin prescripteur
                                    <input v-model="form.external_prescriber" name="external_prescriber" maxlength="255" class="mt-1 h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                                </label>
                            </div>
                        </details>
                    </div>

                    <div class="min-h-[220px] flex-1 overflow-y-auto p-4 sm:p-5">
                        <div v-if="cart.length" class="space-y-3">
                            <article v-for="item in paginatedCart" :key="item.medicine.uuid" class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-800 dark:text-white">{{ item.medicine.name }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">{{ money(item.medicine.sale_price) }} l’unité</p>
                                    </div>
                                    <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20" :aria-label="`Retirer ${item.medicine.name}`" @click="removeLine(item.medicine.uuid)"><Icon name="trash" /></button>
                                </div>
                                <div class="mt-3 flex items-center gap-2">
                                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-lg text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" :aria-label="`Retirer une unité de ${item.medicine.name}`" @click="changeQuantity(item, -1)">−</button>
                                    <input v-model.number="item.line.quantity" :name="`lines.${form.lines.indexOf(item.line)}.quantity`" type="number" min="1" :max="item.medicine.available_quantity" class="h-10 w-16 rounded-lg border border-gray-200 bg-white px-2 text-center text-base font-bold dark:border-gray-800 dark:bg-gray-950 dark:text-white" :aria-label="`Quantité de ${item.medicine.name}`" @change="normalizeQuantity(item)">
                                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-lg text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" :aria-label="`Ajouter une unité de ${item.medicine.name}`" @click="changeQuantity(item, 1)">+</button>
                                    <span class="ms-auto text-base font-bold text-slate-800 dark:text-white">{{ money(Number(item.medicine.sale_price) * Number(item.line.quantity)) }}</span>
                                </div>
                                <input :name="`lines.${form.lines.indexOf(item.line)}.medicine_uuid`" :value="item.medicine.uuid" type="hidden">
                            </article>

                            <nav v-if="cartPageCount > 1" class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900" aria-label="Pages du panier">
                                <button type="button" class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 font-semibold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" :disabled="cartPage === 1" @click="cartPage--">Préc.</button>
                                <span class="text-slate-500">Page <strong class="text-slate-800 dark:text-white">{{ cartPage }}</strong> / {{ cartPageCount }}</span>
                                <button type="button" class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 font-semibold text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300" :disabled="cartPage === cartPageCount" @click="cartPage++">Suiv.</button>
                            </nav>
                        </div>

                        <div v-else class="flex h-full min-h-52 flex-col items-center justify-center text-center">
                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-2xl text-slate-300 dark:bg-gray-900"><Icon name="cart" /></span>
                            <p class="mt-3 text-sm font-semibold text-slate-800 dark:text-white">Le panier est vide</p>
                            <p class="mt-1 max-w-xs text-sm text-slate-500">Touchez un médicament pour l’ajouter.</p>
                        </div>
                    </div>

                    <footer class="shrink-0 border-t border-gray-200 bg-gray-50 p-4 dark:border-gray-900 dark:bg-gray-1000 sm:p-5">
                        <Button class="w-full justify-center" size="lg" type="submit" :disabled="!canSubmit">
                            <Icon name="file-text" /><span class="ms-2">{{ form.processing ? 'Transmission…' : 'Créer et transmettre à la Caisse' }}</span>
                        </Button>
                    </footer>
                </aside>
            </div>
        </form>

        <Teleport to="body">
            <div
                v-if="showCreateConfirmation"
                class="fixed inset-0 z-[1500] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
                aria-labelledby="counter-sale-confirmation-title"
                @click.self="showCreateConfirmation = false"
            >
                <section class="w-full max-w-md overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-950">
                    <header class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-xl text-primary-600 dark:bg-primary-950/30 dark:text-primary-300"><Icon name="shield-check" /></span>
                        <div>
                            <h2 id="counter-sale-confirmation-title" class="text-lg font-bold text-slate-800 dark:text-white">Confirmer la vente</h2>
                            <p class="mt-1 text-sm text-slate-500">Le ticket sera créé et transmis à la Caisse, où le client paiera.</p>
                        </div>
                    </header>

                    <dl class="grid grid-cols-2 divide-x divide-gray-200 border-b border-gray-200 bg-gray-50/70 dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="px-5 py-3"><dt class="text-xs text-slate-500">Articles</dt><dd class="mt-0.5 text-lg font-bold text-slate-800 dark:text-white">{{ cartQuantity }}</dd></div>
                        <div class="px-5 py-3"><dt class="text-xs text-slate-500">Total</dt><dd class="mt-0.5 text-lg font-bold text-slate-800 dark:text-white">{{ money(cartTotal) }}</dd></div>
                    </dl>

                    <div class="space-y-3 p-5">
                        <p v-if="form.customer_name" class="text-sm text-slate-600 dark:text-slate-300">Client : <strong>{{ form.customer_name }}</strong></p>
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
                            {{ canPrintTicket ? 'Le ticket s’imprime aussitôt, puis le panier se vide pour le client suivant.' : 'Le panier se vide ensuite pour le client suivant.' }}
                        </p>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-900/40 sm:flex-row sm:justify-end">
                        <Button variant="white-outline" size="rg" type="button" @click="showCreateConfirmation = false">Annuler</Button>
                        <Button size="rg" type="button" @click="confirmCreation">
                            <Icon :name="canPrintTicket ? 'printer' : 'file-text'" />
                            <span class="ms-2">{{ canPrintTicket ? 'Imprimer et transmettre à la Caisse' : 'Créer et transmettre à la Caisse' }}</span>
                        </Button>
                    </footer>
                </section>
            </div>
        </Teleport>
    </section>
</template>
