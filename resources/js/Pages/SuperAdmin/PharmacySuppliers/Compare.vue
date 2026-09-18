<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { Check, PackageCheck, Search, ShoppingCart, TriangleAlert, Trash2 } from 'lucide-vue-next';
import { formatMoney } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/**
 * Comparing before ordering: one line per medicine, every supplier's current
 * price side by side. A purchase order belongs to one supplier (ADR-097), so
 * a basket spanning three suppliers is sent as three draft orders.
 */
const props = defineProps({
    targetSite: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    medicines: { type: Array, default: () => [] },
    selectedSuppliers: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const listHref = computed(() => `/super-admin/pharmacy-suppliers?site=${props.targetSite.code}`);
const search = ref('');
const chosen = ref([...props.selectedSuppliers]);

const toggleSupplier = (uuid) => {
    chosen.value = chosen.value.includes(uuid) ? chosen.value.filter((item) => item !== uuid) : [...chosen.value, uuid];
    router.get(`/super-admin/pharmacy-suppliers/${props.targetSite.code}/commander`, { suppliers: chosen.value }, {
        preserveState: true, preserveScroll: true, replace: true,
    });
};

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle
        ? props.medicines.filter((medicine) => `${medicine.name} ${medicine.code} ${medicine.quotes.map((quote) => quote.reference ?? '').join(' ')}`.toLowerCase().includes(needle))
        : props.medicines;
});

// key: `${row}|${supplier}` — the same product can be ordered from two
// suppliers at once, each with its own quantity. A supplier listing the same
// product under two references gets one line only: an order takes a product
// once (ADR-098), so choosing the other reference replaces the first.
const basket = ref({});
const keyOf = (medicine, quote) => `${medicine.key}|${quote.supplier_uuid}`;
const inBasket = (medicine, quote) => basket.value[keyOf(medicine, quote)]?.quote_key === quote.key;

const addToBasket = (medicine, quote) => {
    const key = keyOf(medicine, quote);

    if (inBasket(medicine, quote)) {
        delete basket.value[key];

        return;
    }

    basket.value[key] = {
        quote_key: quote.key,
        medicine_uuid: quote.supplier_catalog_item_uuid ? null : medicine.medicine_uuid,
        supplier_catalog_item_uuid: quote.supplier_catalog_item_uuid,
        name: medicine.name,
        reference: quote.reference,
        supplier_uuid: quote.supplier_uuid,
        supplier_name: quote.supplier_name,
        unit_price: quote.price ?? '',
        // Without a supplier price the buyer types the negotiated one.
        priced: quote.price !== null,
        quantity: Math.max(1, (medicine.minimum_stock ?? 0) - (medicine.in_stock ?? 0)) || 1,
    };
};

const basketLines = computed(() => Object.entries(basket.value).map(([key, line]) => ({ key, ...line })));
const bySupplier = computed(() => {
    const groups = new Map();

    for (const line of basketLines.value) {
        if (!groups.has(line.supplier_uuid)) groups.set(line.supplier_uuid, { supplier_uuid: line.supplier_uuid, supplier_name: line.supplier_name, lines: [] });
        groups.get(line.supplier_uuid).lines.push(line);
    }

    return [...groups.values()];
});
const basketTotal = computed(() => basketLines.value.reduce((sum, line) => sum + (Number(line.unit_price) || 0) * (Number(line.quantity) || 0), 0));

const form = useForm({ orders: [], expected_delivery_at: '', notes: '' });
const submit = () => {
    form.orders = bySupplier.value.map((group) => ({
        supplier_uuid: group.supplier_uuid,
        lines: group.lines.map((line) => ({
            ...(line.supplier_catalog_item_uuid ? { supplier_catalog_item_uuid: line.supplier_catalog_item_uuid } : { medicine_uuid: line.medicine_uuid }),
            quantity: Number(line.quantity),
            unit_price: line.unit_price,
        })),
    }));
    form.post(`/super-admin/pharmacy-suppliers/${props.targetSite.code}/commander`, { preserveScroll: true });
};

const stockTone = (medicine) => {
    if (!medicine.in_stock) return 'destructive';

    return medicine.minimum_stock && medicine.in_stock <= medicine.minimum_stock ? 'warning' : 'secondary';
};
</script>

<template>
    <Head title="Comparer et commander" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Fournisseurs pharmacie', href: listHref }, { label: 'Comparer et commander' }]" />

        <PageHeader
            eyebrow="Achats"
            title="Comparer et commander"
            :description="`Le prix de chaque fournisseur pour un même médicament, sur ${targetSite.name}. Choisissez le fournisseur, la quantité, et créez la commande d'achat.`"
            icon="shopping-cart"
        />

        <section v-if="error" class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            <TriangleAlert class="mt-0.5 h-4.5 w-4.5 shrink-0" /><p>{{ error }}</p>
        </section>

        <template v-else>
            <ValidationErrorSummary :errors="form.errors" />

            <section class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <h2 class="font-heading text-base font-bold text-foreground">1 · Fournisseurs à comparer</h2>
                <p class="mt-0.5 text-sm text-muted-foreground">Aucun choix = tous les fournisseurs actifs.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-for="supplier in suppliers"
                        :key="supplier.uuid"
                        type="button"
                        :class="['inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition',
                                 chosen.includes(supplier.uuid) ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-muted-foreground hover:border-primary/40']"
                        @click="toggleSupplier(supplier.uuid)"
                    >
                        <Check v-if="chosen.includes(supplier.uuid)" class="h-4 w-4" />
                        {{ supplier.name }}
                        <span class="text-xs font-normal opacity-70">{{ supplier.offers_count }} produit{{ supplier.offers_count > 1 ? 's' : '' }}</span>
                    </button>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                    <div>
                        <h2 class="font-heading text-base font-bold text-foreground">2 · Comparer les prix</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">{{ visible.length }} médicament{{ visible.length > 1 ? 's' : '' }} proposé{{ visible.length > 1 ? 's' : '' }}</p>
                    </div>
                    <IconInput v-model="search" :icon="Search" placeholder="Rechercher un médicament…" class="w-full sm:w-72" />
                </header>

                <EmptyState
                    v-if="!visible.length"
                    icon="package"
                    title="Aucun produit fournisseur"
                    description="Importez et activez le catalogue d’un fournisseur : ses produits apparaîtront ici."
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">Médicament</th>
                                <th class="px-5 py-3 text-start font-semibold">En stock</th>
                                <th class="px-5 py-3 text-start font-semibold">Offres des fournisseurs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="medicine in visible" :key="medicine.key" class="border-b border-border/70 align-top last:border-0">
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-foreground">{{ medicine.name }}</p>
                                    <p class="mt-0.5 font-mono text-xs text-muted-foreground">{{ medicine.code }}<span v-if="medicine.unit"> · {{ medicine.unit }}</span></p>
                                </td>
                                <td class="px-5 py-4">
                                    <Badge v-if="medicine.in_clinic_catalog" :variant="stockTone(medicine)">{{ medicine.in_stock }}<span v-if="medicine.minimum_stock"> / {{ medicine.minimum_stock }}</span></Badge>
                                    <Badge v-else variant="secondary" title="Au catalogue du fournisseur seulement : il entrera au catalogue de la clinique à la commande.">Nouveau</Badge>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="quote in medicine.quotes"
                                            :key="quote.key"
                                            type="button"
                                            :class="['group flex min-w-44 items-center justify-between gap-3 rounded-lg border px-3 py-2 text-start transition',
                                                     inBasket(medicine, quote) ? 'border-primary bg-primary/10' : 'border-border hover:border-primary/40']"
                                            @click="addToBasket(medicine, quote)"
                                        >
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-medium text-foreground">{{ quote.supplier_name }}</span>
                                                <span v-if="quote.price === null" class="block text-xs text-muted-foreground">Prix non communiqué<span v-if="quote.reference"> · {{ quote.reference }}</span></span>
                                                <span v-else class="block text-xs" :class="quote.price === medicine.best_price ? 'font-bold text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">
                                                    {{ formatMoney(quote.price) }}<span v-if="quote.price === medicine.best_price && medicine.quotes.length > 1"> · le moins cher</span><span v-if="quote.reference"> · {{ quote.reference }}</span>
                                                </span>
                                            </span>
                                            <component :is="inBasket(medicine, quote) ? Check : ShoppingCart" class="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-primary" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="basketLines.length" class="rounded-xl border border-border bg-card shadow-sm">
                <header class="border-b border-border px-5 py-4">
                    <h2 class="font-heading text-base font-bold text-foreground">3 · Commande d’achat</h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        {{ bySupplier.length > 1 ? `${bySupplier.length} commandes seront créées, une par fournisseur.` : 'Une commande sera créée en brouillon.' }}
                    </p>
                </header>

                <div class="divide-y divide-border">
                    <div v-for="group in bySupplier" :key="group.supplier_uuid" class="px-5 py-4">
                        <p class="font-semibold text-foreground">{{ group.supplier_name }}</p>
                        <div class="mt-3 space-y-2">
                            <div v-for="line in group.lines" :key="line.key" class="flex flex-wrap items-center gap-3">
                                <span class="min-w-0 flex-1 truncate text-sm text-foreground">{{ line.name }}</span>
                                <span v-if="line.priced" class="text-sm tabular-nums text-muted-foreground">{{ formatMoney(line.unit_price) }}</span>
                                <input
                                    v-else
                                    v-model="basket[line.key].unit_price"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    placeholder="Prix d’achat"
                                    class="h-10 w-32 rounded-lg border border-amber-400 bg-card px-3 text-end text-sm tabular-nums text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/25"
                                    :aria-label="`Prix d’achat pour ${line.name}`"
                                >
                                <input
                                    v-model.number="basket[line.key].quantity"
                                    type="number"
                                    min="1"
                                    class="h-10 w-24 rounded-lg border border-border bg-card px-3 text-end text-sm tabular-nums text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/25"
                                    :aria-label="`Quantité pour ${line.name}`"
                                >
                                <span class="w-28 text-end text-sm font-semibold tabular-nums text-foreground">{{ formatMoney((Number(line.unit_price) || 0) * (Number(line.quantity) || 0)) }}</span>
                                <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive" :aria-label="`Retirer ${line.name}`" @click="delete basket[line.key]">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="flex flex-wrap items-end justify-between gap-4 border-t border-border px-5 py-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-foreground">Livraison attendue</span>
                            <DatePicker v-model="form.expected_delivery_at" />
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-foreground">Remarque</span>
                            <input v-model="form.notes" type="text" maxlength="2000" class="h-11 w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/25">
                        </label>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-end">
                            <p class="text-xs font-semibold text-muted-foreground">Total estimé</p>
                            <p class="text-2xl font-bold tabular-nums text-foreground">{{ formatMoney(basketTotal) }}</p>
                        </div>
                        <Button size="lg" :disabled="form.processing" @click="submit">
                            <PackageCheck class="h-4 w-4" />{{ form.processing ? 'Création…' : 'Créer la commande d’achat' }}
                        </Button>
                    </div>
                </footer>
            </section>

            <p v-else class="flex items-start gap-2 px-1 text-sm text-muted-foreground">
                <ShoppingCart class="mt-0.5 h-4 w-4" />Cliquez sur l’offre d’un fournisseur pour l’ajouter à la commande.
            </p>
        </template>
    </div>
</template>
