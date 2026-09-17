<script setup>
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import FormSection from '@/Components/UI/FormSection.vue';
import { FileSpreadsheet, Info, Plus, Save, Trash2 } from 'lucide-vue-next';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — the purchase order form, shared by the clinic and the central
 * portal. The clinic lets the pharmacist choose the supplier; the portal
 * opens it from one supplier folder, so the supplier is fixed.
 */
const props = defineProps({
    // null when the supplier is fixed by the page.
    suppliers: { type: Array, default: null },
    supplierUuid: { type: String, default: '' },
    supplierName: { type: String, default: '' },
    // Ce que ce fournisseur peut livrer : les médicaments déjà au catalogue
    // de la clinique (`uuid`) et les lignes de son catalogue qu'elle n'a pas
    // encore reprises (`catalog_item_uuid`). Chacun peut porter le prix
    // fournisseur en cours.
    medicines: { type: Array, default: () => [] },
    submitUrl: { type: Function, required: true },
    cancelHref: { type: String, required: true },
    // A draft being corrected: its lines fill the form and it is sent with PUT.
    order: { type: Object, default: null },
    // Où aller quand le catalogue de la clinique est encore vide : les
    // lignes du catalogue de ce fournisseur, d'où un produit s'ajoute.
    catalogHref: { type: String, default: null },
});

// Un produit est désigné soit par le médicament de la clinique, soit par la
// ligne de catalogue à reprendre : une seule valeur de <select> pour les
// deux, séparée juste avant l'envoi.
const productKey = (product) => (product.uuid ? `med:${product.uuid}` : `cat:${product.catalog_item_uuid}`);
const splitKey = (key) => (key.startsWith('med:')
    ? { medicine_uuid: key.slice(4), supplier_catalog_item_uuid: null }
    : { medicine_uuid: null, supplier_catalog_item_uuid: key.slice(4) });

const blankLine = () => ({ product_key: '', quantity_ordered: 1, unit_price: '' });

const form = useForm({
    supplier_uuid: props.supplierUuid,
    expected_delivery_at: props.order?.expected_delivery_at ?? '',
    notes: props.order?.notes ?? '',
    lines: props.order?.lines.length
        ? props.order.lines.map((line) => ({ product_key: `med:${line.medicine_uuid}`, quantity_ordered: line.quantity_ordered, unit_price: String(line.unit_price) }))
        : [blankLine()],
});

const emit = defineEmits(['supplier-change']);

// Changer de fournisseur change la liste des produits : les lignes déjà
// saisies désignaient ceux d'un autre, elles repartent à zéro. La date et
// la remarque, elles, ne dépendent pas du fournisseur et restent.
watch(() => props.supplierUuid, (uuid) => {
    if (uuid === form.supplier_uuid) return;
    form.supplier_uuid = uuid;
    form.lines = [blankLine()];
});

const productByKey = computed(() => Object.fromEntries(props.medicines.map((product) => [productKey(product), product])));
const inClinic = computed(() => props.medicines.filter((product) => product.in_clinic_catalog !== false));
const fromCatalog = computed(() => props.medicines.filter((product) => product.in_clinic_catalog === false));
const pricedCount = computed(() => props.medicines.filter((medicine) => medicine.quoted_price).length);
const supplierLabel = computed(() => props.supplierName || props.suppliers?.find((supplier) => supplier.uuid === form.supplier_uuid)?.name || '');

// The supplier's current price fills an empty price, never one already typed.
const onMedicineChange = (line) => {
    const price = productByKey.value[line.product_key]?.quoted_price;
    if (price && line.unit_price === '') line.unit_price = price;
};

const addLine = () => form.lines.push(blankLine());
const removeLine = (index) => { if (form.lines.length > 1) form.lines.splice(index, 1); };
const step = (line, delta) => { line.quantity_ordered = Math.max(1, (Number(line.quantity_ordered) || 1) + delta); };
const lineTotal = (line) => (Number(line.quantity_ordered) || 0) * (Number(line.unit_price) || 0);
const total = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));
const units = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.quantity_ordered) || 0), 0));
const readyLines = computed(() => form.lines.filter((line) => line.product_key && Number(line.unit_price) > 0).length);
const newProducts = computed(() => form.lines.filter((line) => line.product_key.startsWith('cat:')).length);

const inputClass = 'h-11 w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/25 ';
const labelClass = 'mb-1.5 block text-sm font-medium text-foreground';

const submit = () => {
    if (!form.supplier_uuid) return;
    const transformed = form.transform(({ supplier_uuid, lines, ...data }) => ({
        ...data,
        lines: lines.map(({ product_key, ...line }) => ({ ...line, ...splitKey(product_key) })),
    }));
    if (props.order) transformed.put(props.submitUrl(form.supplier_uuid), { preserveScroll: true });
    else transformed.post(props.submitUrl(form.supplier_uuid), { preserveScroll: true });
};
</script>

<template>
    <form class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]" @submit.prevent="submit">
        <div class="space-y-5">
            <ValidationErrorSummary :errors="form.errors" />

            <FormSection icon="truck" title="La commande" description="À qui elle est passée et quand la marchandise est attendue.">
                <div :class="['grid gap-4', suppliers ? 'md:grid-cols-3' : 'md:grid-cols-2']">
                    <label v-if="suppliers" class="block">
                        <span :class="labelClass">Fournisseur <span class="text-red-500">*</span></span>
                        <select v-model="form.supplier_uuid" :class="inputClass" required @change="emit('supplier-change', form.supplier_uuid)">
                            <option value="">Choisir un fournisseur</option>
                            <option v-for="supplier in suppliers" :key="supplier.uuid" :value="supplier.uuid">{{ supplier.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span :class="labelClass">Livraison attendue le</span>
                        <input v-model="form.expected_delivery_at" type="date" :class="inputClass">
                    </label>
                    <label class="block">
                        <span :class="labelClass">Remarque</span>
                        <input v-model="form.notes" type="text" maxlength="2000" :class="inputClass" placeholder="Ex. livraison urgente">
                    </label>
                </div>
            </FormSection>

            <section v-if="!form.supplier_uuid" class="rounded-xl border border-border bg-muted/50 p-5">
                <h2 class="font-heading text-base font-bold text-foreground">Choisissez d’abord le fournisseur</h2>
                <p class="mt-1 text-sm leading-6 text-muted-foreground">
                    Ce qu’il y a à commander dépend de lui : ses produits déjà au catalogue de la clinique, et les lignes de son catalogue.
                </p>
            </section>

            <section v-else-if="!medicines.length" class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
                <h2 class="font-heading text-base font-bold text-amber-900 dark:text-amber-200">Aucun produit à commander</h2>
                <p class="mt-1 text-sm leading-6 text-amber-900/90 dark:text-amber-200/90">
                    Ce fournisseur n’a aucun médicament au catalogue de la clinique, et son catalogue à lui est vide ou n’a pas encore été importé.
                    Importez son catalogue : ses produits deviendront commandables directement, sans avoir à les saisir un par un.
                </p>
                <Button v-if="catalogHref" :as="Link" :href="catalogHref" size="rg" class="mt-4">
                    <FileSpreadsheet class="h-4 w-4" />Ouvrir le catalogue du fournisseur
                </Button>
            </section>

            <FormSection v-else icon="capsule" title="Médicaments commandés">
                <template #description>
                    <template v-if="pricedCount">{{ pricedCount }} produit{{ pricedCount > 1 ? 's' : '' }} avec un prix fournisseur, en tête de liste : le prix se remplit tout seul.</template>
                    <template v-else>Choisissez le produit, la quantité et le prix unitaire.</template>
                </template>
                <template #actions>
                    <Button type="button" size="rg" variant="white-outline" @click="addLine"><Plus class="h-4 w-4" />Ajouter un médicament</Button>
                </template>

                <div class="space-y-3">
                    <div v-for="(line, index) in form.lines" :key="index" class="rounded-xl border border-border bg-muted/50 p-4 /30">
                        <div class="grid items-end gap-3 lg:grid-cols-[32px_minmax(0,1fr)_150px_160px_130px_40px]">
                            <span class="hidden h-11 items-center justify-center rounded-full text-sm font-bold text-muted-foreground lg:flex">{{ index + 1 }}</span>
                            <label class="block">
                                <span :class="labelClass">Produit</span>
                                <select v-model="line.product_key" :class="inputClass" required @change="onMedicineChange(line)">
                                    <option value="">Choisir un produit</option>
                                    <optgroup v-if="inClinic.length" label="Déjà au catalogue de la clinique">
                                        <option v-for="product in inClinic" :key="productKey(product)" :value="productKey(product)">{{ product.name }} · {{ product.code }}{{ product.quoted_price ? ` — ${formatMoney(product.quoted_price)}` : '' }}</option>
                                    </optgroup>
                                    <optgroup v-if="fromCatalog.length" label="Au catalogue du fournisseur (sera ajouté à la commande)">
                                        <option v-for="product in fromCatalog" :key="productKey(product)" :value="productKey(product)">{{ product.name }} · {{ product.code }}{{ product.quoted_price ? ` — ${formatMoney(product.quoted_price)}` : '' }}</option>
                                    </optgroup>
                                </select>
                            </label>
                            <div>
                                <span :class="labelClass">Quantité</span>
                                <div class="flex h-11 overflow-hidden rounded-lg border border-border bg-card">
                                    <button type="button" class="w-10 text-lg text-muted-foreground hover:bg-muted" :aria-label="`Diminuer la ligne ${index + 1}`" @click="step(line, -1)">−</button>
                                    <input v-model.number="line.quantity_ordered" type="number" min="1" class="w-full min-w-0 border-0 bg-transparent text-center text-sm font-semibold text-foreground outline-none" required>
                                    <button type="button" class="w-10 text-lg text-muted-foreground hover:bg-muted" :aria-label="`Augmenter la ligne ${index + 1}`" @click="step(line, 1)">+</button>
                                </div>
                            </div>
                            <label class="block">
                                <span :class="labelClass">Prix unitaire</span>
                                <span class="relative block">
                                    <input v-model="line.unit_price" type="number" min="0.01" step="0.01" :class="[inputClass, 'pe-14 text-end']" required>
                                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">MGA</span>
                                </span>
                            </label>
                            <div class="text-end">
                                <span :class="labelClass">Sous-total</span>
                                <p class="flex h-11 items-center justify-end font-bold tabular-nums text-foreground">{{ formatMoney(lineTotal(line)) }}</p>
                            </div>
                            <button type="button" class="flex h-11 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-red-50 hover:text-red-600 disabled:opacity-30 dark:hover:bg-red-950/30" :disabled="form.lines.length <= 1" :aria-label="`Retirer la ligne ${index + 1}`" @click="removeLine(index)"><Trash2 class="h-4 w-4" /></button>
                        </div>
                        <p v-if="productByKey[line.product_key]?.quoted_price && String(line.unit_price) !== String(productByKey[line.product_key].quoted_price)" class="mt-2 text-xs text-amber-600">
                            Le prix du fournisseur est {{ formatMoney(productByKey[line.product_key].quoted_price) }}.
                        </p>
                        <p v-else-if="line.product_key.startsWith('cat:')" class="mt-2 text-xs text-muted-foreground">
                            Nouveau produit : il entrera au catalogue de la clinique à l’enregistrement, sans prix de vente — vous le fixerez après la réception.
                        </p>
                    </div>
                </div>

                <button type="button" class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-border py-3 text-sm font-semibold text-muted-foreground transition hover:border-primary/40 hover:text-primary" @click="addLine">
                    <Plus class="h-4 w-4" />Ajouter une ligne
                </button>
            </FormSection>
        </div>

        <aside class="space-y-3 xl:sticky xl:top-20">
            <section class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <h2 class="font-heading text-base font-bold text-foreground">Récapitulatif</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Fournisseur</dt><dd class="truncate text-end font-semibold text-foreground">{{ supplierLabel || '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Lignes complètes</dt><dd class="font-semibold tabular-nums text-foreground">{{ readyLines }} / {{ form.lines.length }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Unités</dt><dd class="font-semibold tabular-nums text-foreground">{{ units }}</dd></div>
                    <div v-if="newProducts" class="flex justify-between gap-3"><dt class="text-muted-foreground">Nouveaux au catalogue</dt><dd class="font-semibold tabular-nums text-foreground">{{ newProducts }}</dd></div>
                </dl>
                <div class="mt-4 rounded-lg bg-primary/10 px-4 py-3">
                    <p class="text-xs font-semibold text-primary">Total estimé</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums text-primary">{{ formatMoney(total) }}</p>
                </div>
                <div class="mt-4 flex flex-col gap-2">
                    <Button type="submit" size="lg" class="w-full justify-center" :disabled="form.processing || !form.supplier_uuid || !readyLines">
                        <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : (order ? 'Enregistrer les modifications' : 'Enregistrer le brouillon') }}
                    </Button>
                    <Button :as="Link" :href="cancelHref" size="lg" variant="white-outline" class="w-full justify-center">Annuler</Button>
                </div>
            </section>
            <p class="flex items-start gap-2 px-1 text-xs text-muted-foreground"><Info class="mt-0.5 h-4 w-4" />Le brouillon n’engage rien : vous l’enverrez au fournisseur après vérification. Une commande ne modifie jamais le stock.</p>
        </aside>
    </form>
</template>
