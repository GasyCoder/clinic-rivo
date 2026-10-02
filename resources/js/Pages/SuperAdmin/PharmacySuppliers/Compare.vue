<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import IconInput from '@/Components/Shadcn/IconInput.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import ConfirmModal from '@/Components/Shadcn/ConfirmModal.vue';
import Select from '@/Components/Shadcn/Select.vue';
import Textarea from '@/Components/Shadcn/Textarea.vue';
import ProductMatchingSettings from '@/Components/Pharmacy/ProductMatchingSettings.vue';
import PriceComparisonSettings from '@/Components/Pharmacy/PriceComparisonSettings.vue';
import SupplierAiMatchingPanel from '@/Components/Pharmacy/SupplierAiMatchingPanel.vue';
import {
    BookA, Check, GitMerge, Palette, Layers, Link2, PackageCheck, Scale, Search, ShoppingCart, Sparkles, Split, Store, TriangleAlert, Trash2, Unlink, X,
} from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/utilities/pharmacyStatus';
import {
    gapLabel, orderQuotes, priceTier, resolvePriceComparison, tierColor, tierStyles,
} from '@/utilities/priceComparison';
import {
    SEVERAL, compareFamilies, countBy, coverageOf, familyOf, groupByFamily, suppliersOf,
} from '@/utilities/supplierComparison';

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
    toReconcile: { type: Number, default: 0 },
    // ADR-241 — propositions de l'IA en attente, droit de décider, IA configurée.
    proposedByAi: { type: Number, default: 0 },
    canManageEquivalences: { type: Boolean, default: false },
    aiAvailable: { type: Boolean, default: false },
    selectedSuppliers: { type: Array, default: () => [] },
    // ADR-242 — couleurs du moins cher / plus cher, propres au compte.
    priceComparison: { type: Object, default: () => ({}) },
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

/*
 * ADR-181 — deux fournisseurs ne nomment pas un produit de la même façon.
 * Tant que leurs deux libellés restent deux lignes, leurs prix ne se
 * comparent pas, et commander la ligne du fournisseur créerait un second
 * produit avec son propre stock. Ce filtre montre ce qu'il y a à rapprocher.
 */
const onlyToReconcile = ref(false);

/*
 * ADR-181, amendement du 2026-09-24 — ce qui se compare, et par famille
 * (règles dans utilities/supplierComparison.js). Chaque produit tombe dans
 * une seule case — « chez plusieurs fournisseurs » ou « seulement chez X » —,
 * et la liste se range par famille au lieu de dérouler l'alphabet.
 */
const coverage = ref('ALL');
const family = ref('');

const matchesSearch = (medicine) => {
    const needle = search.value.trim().toLowerCase();

    return !needle || `${medicine.name} ${medicine.code} ${medicine.quotes.map((quote) => quote.reference ?? '').join(' ')}`.toLowerCase().includes(needle);
};
const matchesReconcile = (medicine) => !onlyToReconcile.value || Boolean(medicine.suggestions?.length || medicine.peers?.length);
const matchesCoverage = (medicine) => coverage.value === 'ALL' || coverageOf(medicine) === coverage.value;
const matchesFamily = (medicine) => !family.value || familyOf(medicine) === family.value;

// Chaque compte est ce que donnerait un clic sur sa case, les autres filtres
// restant appliqués : une case qui annonce 5 n'ouvre jamais une liste vide.
const coverageCounts = computed(() => {
    const pool = props.medicines.filter((medicine) => matchesSearch(medicine) && matchesReconcile(medicine) && matchesFamily(medicine));

    return { total: pool.length, byCoverage: countBy(pool, coverageOf) };
});

const quotingSuppliers = computed(() => {
    const quoting = new Set(props.medicines.flatMap(suppliersOf));

    return props.suppliers.filter((supplier) => quoting.has(supplier.uuid));
});
// Avec deux fournisseurs seulement, « plusieurs » veut dire « les deux ».
const severalLabel = computed(() => (quotingSuppliers.value.length === 2 ? 'Chez les deux fournisseurs' : 'Chez plusieurs fournisseurs'));
const coverageOptions = computed(() => [
    { value: 'ALL', label: 'Tous', count: coverageCounts.value.total },
    {
        value: SEVERAL,
        label: severalLabel.value,
        count: coverageCounts.value.byCoverage.get(SEVERAL) ?? 0,
        hint: 'Proposés par au moins deux fournisseurs : leurs prix se comparent.',
    },
    ...quotingSuppliers.value.map((supplier) => ({
        value: `ONLY:${supplier.uuid}`,
        label: `Seulement chez ${supplier.name}`,
        count: coverageCounts.value.byCoverage.get(`ONLY:${supplier.uuid}`) ?? 0,
        hint: 'Aucun autre fournisseur ne propose ces produits : il n’y a rien à comparer.',
    })),
]);
// Un fournisseur retiré de la comparaison emporte sa case : on revient à « Tous ».
watch(coverageOptions, (options) => {
    if (!options.some((option) => option.value === coverage.value)) coverage.value = 'ALL';
});

const familyOptions = computed(() => {
    const counts = countBy(
        props.medicines.filter((medicine) => matchesSearch(medicine) && matchesReconcile(medicine) && matchesCoverage(medicine)),
        familyOf,
    );

    // Une famille choisie reste proposée même quand un autre filtre l'a vidée :
    // sinon on ne pourrait plus la quitter.
    if (family.value && !counts.has(family.value)) counts.set(family.value, 0);

    return [...counts.entries()]
        .sort(([first], [second]) => compareFamilies(first, second))
        .map(([label, count]) => ({ label, count }));
});
const familySelectOptions = computed(() => [
    { value: '', label: 'Toutes les familles' },
    ...familyOptions.value.map((option) => ({ value: option.label, label: `${option.label} · ${option.count}` })),
]);

const visible = computed(() => props.medicines.filter(
    (medicine) => matchesSearch(medicine) && matchesReconcile(medicine) && matchesCoverage(medicine) && matchesFamily(medicine),
));

// La liste est rangée par famille, puis par nom : on va à la sienne au lieu de
// parcourir tout l'alphabet. « Sans famille » ferme la marche.
const visibleByFamily = computed(() => groupByFamily(visible.value));

const filtered = computed(() => coverage.value !== 'ALL' || Boolean(family.value) || onlyToReconcile.value || Boolean(search.value.trim()));
const resetFilters = () => {
    coverage.value = 'ALL';
    family.value = '';
    onlyToReconcile.value = false;
    search.value = '';
};

// Le produit que la clinique tient déjà, tel que le comparateur le montre :
// c'est lui qui porte les prix auxquels celui-ci viendra se comparer.
const rowOf = (medicineUuid) => props.medicines.find((medicine) => medicine.medicine_uuid === medicineUuid) ?? null;

// Une ligne de catalogue sans prix n'a rien à rattacher : le rattachement est
// justement ce qui crée le prix d'achat (ADR-098).
const linkableQuotes = (medicine) => medicine.quotes.filter((quote) => quote.supplier_catalog_item_uuid && quote.can_link);

const reconcile = ref(null);
const reconcileForm = useForm({ medicine_uuid: '', change_reason: '' });

const openReconcile = (medicine, suggestion) => {
    const quotes = linkableQuotes(medicine);
    reconcile.value = { medicine, suggestion, quotes, quote: quotes[0] ?? null };
    reconcileForm.clearErrors();
    reconcileForm.medicine_uuid = suggestion.medicine_uuid;
    reconcileForm.change_reason = `Même produit que « ${suggestion.name} », que ce fournisseur nomme « ${medicine.name} ».`;
};

const submitReconcile = () => {
    const { quote } = reconcile.value;
    const site = props.targetSite.code;
    const path = `/super-admin/pharmacy-suppliers/${site}/${quote.supplier_uuid}/catalogs/${quote.supplier_catalog_uuid}/items/${quote.supplier_catalog_item_uuid}/link`;

    reconcileForm.post(path, {
        preserveScroll: true,
        onSuccess: () => { reconcile.value = null; },
    });
};

/*
 * ADR-241 — dire de deux produits fournisseurs qu'ils sont le même, ou deux.
 * « Le même » réunit leurs prix sur une ligne, sans rien créer au catalogue
 * de la clinique ; « deux produits » les sépare et fait taire la proposition.
 * C'est le site qui mémorise et qui audite.
 */
const equivalenceBase = computed(() => `/super-admin/pharmacy-suppliers/${props.targetSite.code}/equivalences`);
const deciding = ref(false);
const decide = (payload, onSuccess = null) => {
    deciding.value = true;
    router.post(equivalenceBase.value, payload, {
        preserveScroll: true,
        onSuccess: () => { onSuccess?.(); },
        onFinish: () => { deciding.value = false; },
    });
};

const samePair = ref(null);
const openSame = (medicine, peer) => { samePair.value = { medicine, peer }; };
const confirmSame = () => {
    const { medicine, peer } = samePair.value;
    decide({ item_uuid: medicine.members.item_uuids[0], status: 'SAME', other_item_uuids: [peer.item_uuid] }, () => { samePair.value = null; });
};
const refusePeer = (medicine, peer) => decide({ item_uuid: medicine.members.item_uuids[0], status: 'DIFFERENT', other_item_uuids: [peer.item_uuid] });
const refuseSuggestion = (medicine, suggestion) => decide({ item_uuid: medicine.members.item_uuids[0], status: 'DIFFERENT', medicine_uuids: [suggestion.medicine_uuid] });

// Une ligne réunit plusieurs produits fournisseurs (règle ou décision) : on
// peut en séparer un, qui redevient sa propre ligne.
const memberCount = (medicine) => (medicine.members?.item_uuids?.length ?? 0) + (medicine.members?.medicine_uuid ? 1 : 0);
const canSeparate = (medicine, quote) => props.canManageEquivalences && Boolean(quote.supplier_catalog_item_uuid) && memberCount(medicine) > 1;
const separate = (medicine, quote) => decide({
    item_uuid: quote.supplier_catalog_item_uuid,
    status: 'DIFFERENT',
    other_item_uuids: medicine.members.item_uuids.filter((uuid) => uuid !== quote.supplier_catalog_item_uuid),
    medicine_uuids: medicine.members.medicine_uuid ? [medicine.members.medicine_uuid] : [],
});

const settingsOpen = ref(false);
const supplierNames = (medicine) => [...new Set(medicine.quotes.map((quote) => quote.supplier_name).filter(Boolean))].join(', ');

// Les refus du site sur une décision ou sur l'IA, dits en tête de page.
const page = usePage();
const decisionErrors = computed(() => Object.fromEntries(
    Object.entries(page.props.errors ?? {}).filter(([key]) => ['ai', 'site', 'status', 'item_uuid', 'other_item_uuids', 'medicine_uuids', 'actor'].includes(key)),
));

// ADR-242 — « Rapprocher avec l'IA » par lots : le panneau prépare les lots,
// les envoie un par un et montre leur état ; seuls les libellés partent.
const aiPanel = ref(null);
const aiRunning = ref(false);
const runAi = () => aiPanel.value?.start({ suppliers: chosen.value, family: family.value || null });
const showAiProposals = () => {
    router.reload({
        only: ['medicines', 'toReconcile', 'proposedByAi'],
        onSuccess: () => { onlyToReconcile.value = true; },
    });
};

// ADR-242 — le moins cher, le plus cher et l'écart, selon les réglages du compte.
const priceSettings = computed(() => resolvePriceComparison(props.priceComparison));
const priceSettingsOpen = ref(false);
const quotesOf = (medicine) => orderQuotes(medicine.quotes, priceSettings.value).map((quote) => {
    const info = priceTier(medicine.quotes, quote, priceSettings.value);

    return { quote, info, styles: tierStyles(info.tier, priceSettings.value), gap: gapLabel(info, priceSettings.value, formatMoney) };
});
const tierLabels = { best: 'le moins cher', worst: 'le plus cher' };
const legend = computed(() => [
    { key: 'best', label: 'Moins cher', color: tierColor('best', priceSettings.value) },
    ...(priceSettings.value.color_middle ? [{ key: 'middle', label: 'Intermédiaire', color: tierColor('middle', priceSettings.value) }] : []),
    { key: 'worst', label: priceSettings.value.min_gap_percent ? `Plus cher (écart ≥ ${priceSettings.value.min_gap_percent} %)` : 'Plus cher', color: tierColor('worst', priceSettings.value) },
]);

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
            <ValidationErrorSummary :errors="decisionErrors" />

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
                    <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                        <button
                            v-if="toReconcile"
                            type="button"
                            :class="['inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition',
                                     onlyToReconcile ? 'border-amber-500 bg-amber-500 text-white' : 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-950/30']"
                            :title="'Des lignes ressemblent à un produit que la clinique tient déjà, ou à celui d’un autre fournisseur, sous un autre nom : leurs prix ne se comparent pas tant qu’ils ne sont pas rapprochés.'"
                            @click="onlyToReconcile = !onlyToReconcile"
                        >
                            <Link2 class="h-3.5 w-3.5" />À rapprocher · {{ toReconcile }}<span v-if="proposedByAi" class="opacity-80">(dont {{ proposedByAi }} par l’IA)</span>
                        </button>
                        <Button v-if="canManageEquivalences && aiAvailable" type="button" variant="outline" size="sm" :disabled="aiRunning" title="Envoie à l’IA, lot par lot, les seuls libellés que la règle n’a pas rapprochés — jamais un prix ni un patient. Ses propositions attendent votre confirmation." @click="runAi">
                            <Sparkles class="h-4 w-4" />{{ aiRunning ? 'L’IA compare…' : 'Rapprocher avec l’IA' }}
                        </Button>
                        <Button type="button" variant="ghost" size="sm" title="Couleurs du moins cher et du plus cher, écart, tri" @click="priceSettingsOpen = true">
                            <Palette class="h-4 w-4" />Affichage des prix
                        </Button>
                        <Button type="button" variant="ghost" size="sm" title="Abréviations et décisions mémorisées" @click="settingsOpen = true">
                            <BookA class="h-4 w-4" />Dictionnaire
                        </Button>
                        <IconInput v-model="search" :icon="Search" placeholder="Rechercher un médicament…" class="w-full sm:w-72" />
                    </div>
                </header>

                <SupplierAiMatchingPanel ref="aiPanel" :site-code="targetSite.code" @busy="(value) => { aiRunning = value; }" @proposals="showAiProposals" />

                <!-- Où le produit est proposé, et sa famille : chaque produit
                     tombe dans une seule case, et la liste se range par famille. -->
                <div v-if="medicines.length" class="flex flex-col gap-3 border-b border-border px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex max-w-full gap-1.5 overflow-x-auto" role="group" aria-label="Où le produit est proposé">
                        <button
                            v-for="option in coverageOptions"
                            :key="option.value"
                            type="button"
                            :aria-pressed="coverage === option.value"
                            :title="option.hint"
                            :class="cn('inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition',
                                       coverage === option.value ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:bg-muted',
                                       !option.count && coverage !== option.value && 'opacity-60')"
                            @click="coverage = option.value"
                        >
                            <Scale v-if="option.value === SEVERAL" class="h-3.5 w-3.5" aria-hidden="true" />
                            <Store v-else-if="option.value !== 'ALL'" class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ option.label }}<span class="tabular-nums opacity-80">· {{ option.count }}</span>
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <Select
                            v-model="family"
                            :icon="Layers"
                            :options="familySelectOptions"
                            placeholder="Toutes les familles"
                            class="w-full sm:w-72"
                            aria-label="Famille"
                        />
                        <Button v-if="filtered" type="button" variant="ghost" size="sm" @click="resetFilters"><X class="h-4 w-4" />Effacer</Button>
                    </div>
                </div>

                <div v-if="priceSettings.show_legend && visible.length" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-border px-5 py-2 text-xs text-muted-foreground">
                    <span v-for="item in legend" :key="item.key" class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: item.color }" aria-hidden="true" />{{ item.label }}
                    </span>
                    <button type="button" class="ms-auto font-semibold text-primary hover:underline" @click="priceSettingsOpen = true">Modifier</button>
                </div>

                <div v-if="!visible.length && medicines.length" class="px-6 py-12 text-center">
                    <Search class="mx-auto h-8 w-8 text-muted-foreground" />
                    <p class="mt-3 font-semibold text-foreground">Aucun produit ne correspond</p>
                    <p class="mt-1 text-sm text-muted-foreground">Changez de famille, de fournisseur ou de recherche.</p>
                    <Button type="button" variant="outline" size="sm" class="mt-4" @click="resetFilters"><X class="h-4 w-4" />Effacer les filtres</Button>
                </div>

                <EmptyState
                    v-else-if="!visible.length"
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
                            <template v-for="group in visibleByFamily" :key="group.label">
                            <tr class="bg-muted/40">
                                <td colspan="3" class="px-5 py-2">
                                    <span class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-foreground"><Layers class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />{{ group.label }}</span>
                                    <span class="ms-2 text-[11px] text-muted-foreground">{{ group.rows.length }} produit{{ group.rows.length > 1 ? 's' : '' }}</span>
                                </td>
                            </tr>
                            <tr v-for="medicine in group.rows" :key="medicine.key" class="border-b border-border/70 align-top last:border-0">
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-foreground">{{ medicine.name }}</p>
                                    <p class="mt-0.5 font-mono text-xs text-muted-foreground">{{ medicine.code }}<span v-if="medicine.unit"> · {{ medicine.unit }}</span></p>

                                    <!-- ADR-181 — la clinique tient peut-être déjà ce produit sous
                                         un autre nom. On le demande, on ne le décide jamais. -->
                                    <div v-if="medicine.suggestions?.length" class="mt-2 space-y-1 border-s-2 border-amber-300 ps-2.5 dark:border-amber-800">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">Peut-être déjà au catalogue</p>
                                        <template v-for="suggestion in medicine.suggestions" :key="suggestion.medicine_uuid">
                                            <button
                                                v-if="linkableQuotes(medicine).length"
                                                type="button"
                                                class="flex w-full items-start gap-1.5 rounded-md px-1.5 py-1 text-start text-xs text-foreground transition hover:bg-amber-50 dark:hover:bg-amber-950/30"
                                                @click="openReconcile(medicine, suggestion)"
                                            >
                                                <Link2 class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                                                <span>« {{ suggestion.name }} » <span class="font-mono text-muted-foreground">{{ suggestion.code }}</span>
                                                    <span class="block font-semibold text-amber-700 dark:text-amber-400">C’est le même produit ?</span></span>
                                            </button>
                                            <p v-else class="px-1.5 text-xs text-muted-foreground">
                                                « {{ suggestion.name }} » — prix non communiqué par ce fournisseur : renseignez-le dans son catalogue pour pouvoir rapprocher.
                                            </p>
                                            <button
                                                v-if="canManageEquivalences && medicine.members?.item_uuids?.length"
                                                type="button"
                                                class="ms-5 inline-flex items-center gap-1 text-[11px] font-semibold text-muted-foreground hover:text-destructive"
                                                :disabled="deciding"
                                                @click="refuseSuggestion(medicine, suggestion)"
                                            ><Unlink class="h-3 w-3" />Ce n’est pas le même</button>
                                        </template>
                                    </div>

                                    <!-- ADR-241 — le même produit chez un autre fournisseur, écrit
                                         autrement : la règle ou l'IA le propose, un humain décide. -->
                                    <div v-if="medicine.peers?.length" class="mt-2 space-y-1.5 border-s-2 border-sky-300 ps-2.5 dark:border-sky-800">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Peut-être le même produit</p>
                                        <div v-for="peer in medicine.peers" :key="peer.row_key" class="rounded-md px-1.5 py-1 text-xs">
                                            <p class="text-foreground">
                                                « {{ peer.name }} »
                                                <span class="text-muted-foreground">· {{ peer.suppliers.join(', ') }}<span v-if="peer.best_price"> · {{ formatMoney(peer.best_price) }}</span></span>
                                                <Badge v-if="peer.source === 'AI'" variant="secondary" class="ms-1 align-middle"><Sparkles class="h-3 w-3" />IA</Badge>
                                            </p>
                                            <p v-if="peer.reason" class="text-[11px] italic text-muted-foreground">{{ peer.reason }}</p>
                                            <div v-if="canManageEquivalences" class="mt-1 flex flex-wrap gap-3">
                                                <button type="button" class="inline-flex items-center gap-1 font-semibold text-sky-700 hover:underline dark:text-sky-400" :disabled="deciding" @click="openSame(medicine, peer)"><GitMerge class="h-3 w-3" />C’est le même produit</button>
                                                <button type="button" class="inline-flex items-center gap-1 font-semibold text-muted-foreground hover:text-destructive" :disabled="deciding" @click="refusePeer(medicine, peer)"><Unlink class="h-3 w-3" />Ce n’est pas le même</button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <Badge v-if="medicine.in_clinic_catalog" :variant="stockTone(medicine)">{{ medicine.in_stock }}<span v-if="medicine.minimum_stock"> / {{ medicine.minimum_stock }}</span></Badge>
                                    <Badge v-else variant="secondary" title="Au catalogue du fournisseur seulement : il entrera au catalogue de la clinique à la commande.">Nouveau</Badge>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <div v-for="{ quote, info, styles, gap } in quotesOf(medicine)" :key="quote.key" class="flex flex-col">
                                        <button
                                            type="button"
                                            :class="['group flex min-w-44 items-center justify-between gap-3 rounded-lg border px-3 py-2 text-start transition',
                                                     inBasket(medicine, quote) ? 'border-primary bg-primary/10 ring-2 ring-primary/30' : 'border-border hover:border-primary/40']"
                                            :style="inBasket(medicine, quote) ? {} : styles.card"
                                            @click="addToBasket(medicine, quote)"
                                        >
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-medium text-foreground">{{ quote.supplier_name }}</span>
                                                <span v-if="quote.price === null" class="block text-xs text-muted-foreground">Prix non communiqué<span v-if="quote.reference"> · {{ quote.reference }}</span></span>
                                                <span v-else class="block text-xs" :class="info.tier ? '' : 'text-muted-foreground'" :style="styles.price">
                                                    {{ formatMoney(quote.price) }}<span v-if="priceSettings.show_labels && tierLabels[info.tier]"> · {{ tierLabels[info.tier] }}</span><span v-if="quote.reference" class="font-normal text-muted-foreground"> · {{ quote.reference }}</span>
                                                </span>
                                                <span v-if="gap" class="block text-[11px] tabular-nums text-muted-foreground">{{ gap }} vs le moins cher</span>
                                            </span>
                                            <component :is="inBasket(medicine, quote) ? Check : ShoppingCart" class="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-primary" />
                                        </button>
                                        <!-- ADR-241 — réunie sur cette ligne sous un autre nom : on relit
                                             le libellé du fournisseur, et on peut la séparer. -->
                                        <p v-if="quote.label" class="mt-1 max-w-56 truncate px-1 text-[11px] text-muted-foreground" :title="quote.label">« {{ quote.label }} »</p>
                                        <button v-if="canSeparate(medicine, quote)" type="button" class="mt-0.5 inline-flex items-center gap-1 self-start px-1 text-[11px] font-semibold text-muted-foreground hover:text-destructive" :disabled="deciding" @click="separate(medicine, quote)">
                                            <Split class="h-3 w-3" />Séparer
                                        </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            </template>
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

        <!-- ADR-181 — on lit les deux libellés avant de dire que c'est le même
             produit : un rapprochement faux crée un prix d'achat sur le mauvais
             article, et le stock suit. -->
        <ConfirmModal
            :open="Boolean(reconcile)"
            title="C’est le même produit ?"
            confirm-label="Oui, c’est le même produit"
            tone="warning"
            :processing="reconcileForm.processing"
            :disabled="!reconcile?.quote || reconcileForm.change_reason.trim().length < 3"
            @update:open="(value) => { if (!value) reconcile = null; }"
            @confirm="submitReconcile"
        >
            <template v-if="reconcile">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Chez le fournisseur</p>
                        <p class="mt-1 font-semibold text-foreground">{{ reconcile.medicine.name }}</p>
                        <p class="font-mono text-xs text-muted-foreground">{{ reconcile.medicine.code }}<span v-if="reconcile.medicine.unit"> · {{ reconcile.medicine.unit }}</span></p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Au catalogue de la clinique</p>
                        <p class="mt-1 font-semibold text-foreground">{{ reconcile.suggestion.name }}</p>
                        <p class="font-mono text-xs text-muted-foreground">{{ reconcile.suggestion.code }}<span v-if="reconcile.suggestion.unit"> · {{ reconcile.suggestion.unit }}</span></p>
                    </div>
                </div>

                <p class="mt-3 text-sm text-muted-foreground">
                    Lisez les deux libellés : un dosage, un volume ou un calibre différent en fait deux produits.
                    Rapprochés, ils n’en font qu’un — et leurs prix se comparent sur une seule ligne.
                </p>

                <!-- Plusieurs fournisseurs écrivent ce libellé : on rattache
                     une ligne de catalogue à la fois, celle qu'on désigne. -->
                <div v-if="reconcile.quotes.length > 1" class="mt-3">
                    <p class="mb-1.5 text-sm font-semibold text-foreground">Quelle ligne rattacher ?</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="quote in reconcile.quotes"
                            :key="quote.key"
                            type="button"
                            :class="['rounded-lg border px-3 py-1.5 text-xs font-semibold transition',
                                     reconcile.quote?.key === quote.key ? 'border-primary bg-primary/10 text-foreground' : 'border-border text-muted-foreground hover:border-primary/40']"
                            @click="reconcile.quote = quote"
                        >{{ quote.supplier_name }}<span v-if="quote.price !== null"> · {{ formatMoney(quote.price) }}</span></button>
                    </div>
                </div>

                <!-- Ce que la clinique paie déjà ce produit : si ce fournisseur
                     y figure, le rapprochement remplacera son prix. -->
                <p v-if="rowOf(reconcile.suggestion.medicine_uuid)?.quotes?.length" class="mt-3 text-xs text-muted-foreground">
                    Prix déjà connus pour « {{ reconcile.suggestion.name }} » :
                    <span v-for="(quote, index) in rowOf(reconcile.suggestion.medicine_uuid).quotes" :key="quote.key">
                        <span v-if="index"> · </span>{{ quote.supplier_name }} {{ quote.price === null ? '—' : formatMoney(quote.price) }}
                    </span>
                </p>

                <label class="mt-3 block">
                    <span class="mb-1.5 block text-sm font-semibold text-foreground">Motif <span class="text-red-500">*</span></span>
                    <Textarea v-model="reconcileForm.change_reason" rows="2" maxlength="1000" />
                    <span class="mt-1 block text-xs text-muted-foreground">Conservé avec le prix d’achat créé par ce rapprochement.</span>
                </label>
                <p v-if="reconcileForm.errors.change_reason" class="mt-1 text-xs font-medium text-red-600">{{ reconcileForm.errors.change_reason }}</p>
                <p v-if="reconcileForm.errors.medicine_uuid" class="mt-1 text-xs font-medium text-red-600">{{ reconcileForm.errors.medicine_uuid }}</p>
            </template>
        </ConfirmModal>

        <!-- ADR-241 — deux libellés de deux fournisseurs : on les relit avant de
             dire que c'est le même produit. Rien n'est créé au catalogue. -->
        <ConfirmModal
            :open="Boolean(samePair)"
            title="C’est le même produit ?"
            confirm-label="Oui, c’est le même produit"
            tone="warning"
            :processing="deciding"
            @update:open="(value) => { if (!value) samePair = null; }"
            @confirm="confirmSame"
        >
            <template v-if="samePair">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ supplierNames(samePair.medicine) }}</p>
                        <p class="mt-1 font-semibold text-foreground">{{ samePair.medicine.name }}</p>
                        <p class="font-mono text-xs text-muted-foreground">{{ samePair.medicine.code }}<span v-if="samePair.medicine.unit"> · {{ samePair.medicine.unit }}</span></p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ samePair.peer.suppliers.join(', ') }}</p>
                        <p class="mt-1 font-semibold text-foreground">{{ samePair.peer.name }}</p>
                        <p class="font-mono text-xs text-muted-foreground">{{ samePair.peer.code }}<span v-if="samePair.peer.unit"> · {{ samePair.peer.unit }}</span></p>
                    </div>
                </div>
                <p class="mt-3 text-sm text-muted-foreground">
                    Un dosage, un volume ou un calibre différent en fait deux produits. Confirmé, leurs prix se comparent sur une seule ligne ;
                    rien n’est ajouté au catalogue de la clinique, et « Séparer » défait la décision.
                </p>
                <p v-if="samePair.peer.source === 'AI'" class="mt-2 text-xs text-muted-foreground">Proposé par l’IA<span v-if="samePair.peer.reason"> : {{ samePair.peer.reason }}</span></p>
            </template>
        </ConfirmModal>

        <PriceComparisonSettings v-model:open="priceSettingsOpen" :settings="priceComparison" />
        <ProductMatchingSettings v-model:open="settingsOpen" :site-code="targetSite.code" :can-manage="canManageEquivalences" />
    </div>
</template>
