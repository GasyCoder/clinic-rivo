<script setup>
import { currencyLabel } from '@/utilities/money';
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Shadcn/Button.vue';
import FormSection from '@/Components/UI/FormSection.vue';
import { CalendarDays, CloudUpload, Copy, FileCheck, Info, ListPlus, Plus, Save, Trash2, X } from 'lucide-vue-next';
import { cn } from '@/lib/cn';
import { formatDate, localToday, toLocalDateInput } from '@/utilities/date';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — the supplier invoice form, shared by the clinic and the central
 * portal. When the page provides the supplier's orders, the invoice can be
 * tied to an order (and its reception) and start from its lines.
 *
 * An invoice is a financial document first: its total is what the supplier
 * billed, and detailing it product by product is a deliberate extra step —
 * what physically arrived is already recorded by the reception.
 */
const props = defineProps({
    // null when the supplier is fixed by the page.
    suppliers: { type: Array, default: null },
    supplierUuid: { type: String, default: '' },
    supplierName: { type: String, default: '' },
    medicines: { type: Array, default: () => [] },
    // [{ uuid, order_number, status_label, lines: [...], receipts: [{ uuid, receipt_number }] }]
    orders: { type: Array, default: () => [] },
    initialOrderUuid: { type: String, default: '' },
    submitUrl: { type: Function, required: true },
    cancelHref: { type: String, required: true },
    // An invoice being corrected: its content fills the form.
    invoice: { type: Object, default: null },
});

const blankLine = () => ({ medicine_uuid: '', description: '', quantity: 1, unit_price: '' });
const supplierUuid = ref(props.supplierUuid);
const initial = props.invoice;

const form = useForm({
    invoice_number: initial?.invoice_number ?? '',
    invoice_date: initial?.invoice_date ?? localToday(),
    due_date: initial?.due_date ?? '',
    purchase_order_uuid: initial
        ? (initial.purchase_order_uuid ?? '')
        : (props.orders.some((order) => order.uuid === props.initialOrderUuid) ? props.initialOrderUuid : ''),
    goods_receipt_uuid: initial?.goods_receipt_uuid ?? '',
    notes: initial?.notes ?? '',
    attachment: null,
    total_amount: initial && !initial.lines.length ? String(initial.total_amount ?? '') : '',
    lines: initial?.lines.length
        ? initial.lines.map((line) => ({ medicine_uuid: line.medicine_uuid, description: line.description, quantity: line.quantity, unit_price: String(line.unit_price) }))
        : [],
});

// Off by default: most invoices are filed as a document and a total.
const detailed = ref(Boolean(initial?.lines.length));
const openDetail = () => {
    detailed.value = true;
    if (!form.lines.length) form.lines.push(blankLine());
};
const closeDetail = () => {
    detailed.value = false;
    form.lines = [];
};

const supplierLabel = computed(() => props.supplierName || props.suppliers?.find((supplier) => supplier.uuid === supplierUuid.value)?.name || '');
const selectedOrder = computed(() => props.orders.find((order) => order.uuid === form.purchase_order_uuid) ?? null);
watch(() => form.purchase_order_uuid, () => {
    // The order of a corrected invoice may no longer be listed: keep its links as they were.
    if (!selectedOrder.value && initial && form.purchase_order_uuid === (initial.purchase_order_uuid ?? '')) return;
    if (!selectedOrder.value?.receipts.some((receipt) => receipt.uuid === form.goods_receipt_uuid)) {
        form.goods_receipt_uuid = selectedOrder.value?.receipts.length === 1 ? selectedOrder.value.receipts[0].uuid : '';
    }
}, { immediate: true });

const medicineName = (uuid) => props.medicines.find((medicine) => medicine.uuid === uuid)?.name ?? '';
const onMedicineChange = (line) => { if (!line.description) line.description = medicineName(line.medicine_uuid); };

// Starts from what was ordered (received quantities when known); every line stays editable.
const copyOrderLines = () => {
    if (!selectedOrder.value) return;
    form.lines = selectedOrder.value.lines.map((line) => ({ ...line }));
    detailed.value = true;
};

const addLine = () => form.lines.push(blankLine());
const removeLine = (index) => { if (form.lines.length > 1) form.lines.splice(index, 1); };
const lineTotal = (line) => (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
const linesTotal = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));
// With a detail, the lines are the total: a typed amount could contradict them.
const total = computed(() => (detailed.value ? linesTotal.value : Number(form.total_amount) || 0));
const readyLines = computed(() => form.lines.filter((line) => line.medicine_uuid && line.description && Number(line.unit_price) > 0).length);
const canSubmit = computed(() => Boolean(supplierUuid.value && form.invoice_number
    && (detailed.value ? readyLines.value === form.lines.length && readyLines.value > 0 : total.value > 0)));

// ADR-175 — la date d'une facture est celle du jour, sauf si le papier du
// fournisseur en porte une autre ; l'échéance se choisit par délai.
const today = localToday();
const customDate = ref(Boolean(initial?.invoice_date) && initial.invoice_date !== today);
const customDue = ref(Boolean(initial?.due_date));
const addDays = (days) => {
    const date = new Date(`${form.invoice_date || today}T00:00:00`);
    date.setDate(date.getDate() + days);

    return toLocalDateInput(date);
};
const dueTerms = [
    { label: 'À réception', value: () => '' },
    { label: '15 jours', value: () => addDays(15) },
    { label: '30 jours', value: () => addDays(30) },
    { label: '60 jours', value: () => addDays(60) },
];
const chip = (active) => cn(
    'rounded-full border px-3 py-1 text-xs font-semibold transition',
    active ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:bg-muted',
);

const inputClass = 'h-11 w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/25 disabled:bg-muted disabled:text-muted-foreground ';
const labelClass = 'mb-1.5 block text-sm font-medium text-foreground';

const submit = () => {
    if (!canSubmit.value) return;
    form
        .transform((data) => (detailed.value
            ? { ...data, total_amount: '' }
            : { ...data, lines: [] }))
        .post(props.submitUrl(supplierUuid.value), { forceFormData: true, preserveScroll: true });
};
</script>

<template>
    <form class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]" @submit.prevent="submit">
        <div class="space-y-5">
            <ValidationErrorSummary :errors="form.errors" />

            <FormSection icon="file-text" title="La facture" description="Recopiez les informations imprimées sur la facture du fournisseur.">
                <div class="grid gap-4 md:grid-cols-3">
                    <label v-if="suppliers" class="block">
                        <span :class="labelClass">Fournisseur <span class="text-red-500">*</span></span>
                        <select v-model="supplierUuid" :class="inputClass" required>
                            <option value="">Choisir un fournisseur</option>
                            <option v-for="supplier in suppliers" :key="supplier.uuid" :value="supplier.uuid">{{ supplier.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span :class="labelClass">Numéro de facture <span class="text-red-500">*</span></span>
                        <input v-model="form.invoice_number" type="text" maxlength="100" :class="inputClass" placeholder="Ex. FA-2026-0142" required>
                    </label>
                    <div class="block">
                        <span :class="labelClass">Date de la facture</span>
                        <div v-if="!customDate" class="flex h-11 items-center justify-between gap-2 rounded-lg border border-border bg-muted/40 px-3 text-sm">
                            <span class="inline-flex items-center gap-2 font-semibold text-foreground"><CalendarDays class="h-4 w-4 text-muted-foreground" />{{ formatDate(form.invoice_date) }}</span>
                            <button type="button" class="text-xs font-semibold text-primary hover:underline" @click="customDate = true">Autre date</button>
                        </div>
                        <div v-else class="flex items-center gap-2">
                            <DatePicker v-model="form.invoice_date" :max="today" required />
                            <Button type="button" size="icon" variant="ghost" aria-label="Revenir à aujourd’hui" @click="form.invoice_date = today; customDate = false"><X class="h-4 w-4" /></Button>
                        </div>
                    </div>
                    <div class="block">
                        <span :class="labelClass">Échéance <span class="font-normal text-muted-foreground">(facultatif)</span></span>
                        <div v-if="!customDue" class="flex flex-wrap items-center gap-1.5 pt-1.5">
                            <button v-for="term in dueTerms" :key="term.label" type="button" :class="chip(form.due_date === term.value())" @click="form.due_date = term.value()">{{ term.label }}</button>
                            <button type="button" :class="chip(false)" @click="customDue = true">Autre date</button>
                        </div>
                        <div v-else class="flex items-center gap-2">
                            <DatePicker v-model="form.due_date" :min="form.invoice_date" />
                            <Button type="button" size="icon" variant="ghost" aria-label="Revenir aux délais habituels" @click="form.due_date = ''; customDue = false"><X class="h-4 w-4" /></Button>
                        </div>
                        <span v-if="form.due_date && !customDue" class="mt-1 block text-xs text-muted-foreground">À payer avant le {{ formatDate(form.due_date) }}</span>
                    </div>
                    <label v-if="!detailed" class="block">
                        <span :class="labelClass">Montant total <span class="text-red-500">*</span></span>
                        <span class="relative block">
                            <input v-model="form.total_amount" type="number" min="0.01" step="0.01" :class="[inputClass, 'pe-14 text-end font-semibold tabular-nums']" placeholder="0" required>
                            <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">{{ currencyLabel() }}</span>
                        </span>
                    </label>
                    <label :class="['block', suppliers ? 'md:col-span-3' : 'md:col-span-2']">
                        <span :class="labelClass">Remarque</span>
                        <input v-model="form.notes" type="text" maxlength="2000" :class="inputClass" placeholder="Ex. remise de 5 % appliquée">
                    </label>
                </div>

                <label class="mt-4 flex cursor-pointer items-center gap-4 rounded-xl border-2 border-dashed border-border px-4 py-4 transition hover:border-primary/40">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><component :is="form.attachment ? FileCheck : CloudUpload" class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-foreground">{{ form.attachment ? form.attachment.name : (invoice?.has_attachment ? 'Remplacer le document (facultatif)' : 'Joindre la facture (facultatif)') }}</span>
                        <span class="block text-xs text-muted-foreground">PDF, photo ou Excel — 10 Mo au maximum</span>
                    </span>
                    <span class="text-sm font-semibold text-primary">Choisir</span>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.xlsx" class="sr-only" @change="form.attachment = $event.target.files[0] ?? null">
                </label>
            </FormSection>

            <FormSection v-if="orders.length" icon="link" title="Rattacher à une commande" description="Facultatif : relie la facture à ce qui a été commandé et reçu.">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block">
                        <span :class="labelClass">Commande concernée</span>
                        <select v-model="form.purchase_order_uuid" :class="inputClass">
                            <option value="">Aucune</option>
                            <option v-for="order in orders" :key="order.uuid" :value="order.uuid">{{ order.order_number }} · {{ order.status_label }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span :class="labelClass">Réception concernée</span>
                        <select v-model="form.goods_receipt_uuid" :class="inputClass" :disabled="!selectedOrder?.receipts.length">
                            <option value="">{{ selectedOrder?.receipts.length ? 'Aucune' : (selectedOrder ? 'Pas encore de réception' : 'Choisissez d’abord une commande') }}</option>
                            <option v-for="receipt in selectedOrder?.receipts ?? []" :key="receipt.uuid" :value="receipt.uuid">{{ receipt.receipt_number }}</option>
                        </select>
                    </label>
                </div>
                <Button v-if="selectedOrder?.lines.length" type="button" size="rg" variant="white-outline" class="mt-4" @click="copyOrderLines"><Copy class="h-4 w-4" />Reprendre les lignes de {{ selectedOrder.order_number }}</Button>
            </FormSection>

            <button
                v-if="!detailed"
                type="button"
                class="flex w-full items-center gap-3 rounded-xl border border-dashed border-border bg-card px-4 py-4 text-start transition hover:border-primary/40"
                @click="openDetail"
            >
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"><ListPlus class="h-5 w-5" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-foreground">Détailler les produits facturés</span>
                    <span class="block text-xs text-muted-foreground">Facultatif. Le montant total suffit pour classer la facture ; c’est la réception qui fait entrer le stock.</span>
                </span>
            </button>

            <FormSection v-else icon="list" title="Lignes de la facture" description="Une ligne par produit facturé ; le libellé est celui écrit sur la facture.">
                <template #actions>
                    <Button type="button" size="rg" variant="white-outline" @click="closeDetail"><X class="h-4 w-4" />Revenir au montant global</Button>
                    <Button type="button" size="rg" variant="white-outline" @click="addLine"><Plus class="h-4 w-4" />Ajouter une ligne</Button>
                </template>

                <div class="space-y-3">
                    <div v-for="(line, index) in form.lines" :key="index" class="rounded-xl border border-border bg-muted/50 p-4 /30">
                        <div class="grid items-end gap-3 lg:grid-cols-[32px_minmax(0,1fr)_minmax(0,1fr)_90px_140px_120px_40px]">
                            <span class="hidden h-11 items-center justify-center text-sm font-bold text-muted-foreground lg:flex">{{ index + 1 }}</span>
                            <label class="block">
                                <span :class="labelClass">Médicament</span>
                                <select v-model="line.medicine_uuid" :class="inputClass" required @change="onMedicineChange(line)">
                                    <option value="">Choisir un médicament</option>
                                    <option v-for="medicine in medicines" :key="medicine.uuid" :value="medicine.uuid">{{ medicine.name }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span :class="labelClass">Libellé sur la facture</span>
                                <input v-model="line.description" type="text" maxlength="255" :class="inputClass" required>
                            </label>
                            <label class="block">
                                <span :class="labelClass">Qté</span>
                                <input v-model.number="line.quantity" type="number" min="1" :class="[inputClass, 'text-center']" required>
                            </label>
                            <label class="block">
                                <span :class="labelClass">Prix d’achat unitaire</span>
                                <span class="relative block">
                                    <input v-model="line.unit_price" type="number" min="0.01" step="0.01" :class="[inputClass, 'pe-14 text-end']" required>
                                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground">{{ currencyLabel() }}</span>
                                </span>
                            </label>
                            <div class="text-end">
                                <span :class="labelClass">Sous-total</span>
                                <p class="flex h-11 items-center justify-end font-bold tabular-nums text-foreground">{{ formatMoney(lineTotal(line)) }}</p>
                            </div>
                            <button type="button" class="flex h-11 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-red-50 hover:text-red-600 disabled:opacity-30 dark:hover:bg-red-950/30" :disabled="form.lines.length <= 1" :aria-label="`Retirer la ligne ${index + 1}`" @click="removeLine(index)"><Trash2 class="h-4 w-4" /></button>
                        </div>
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
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Facture</dt><dd class="truncate text-end font-mono font-semibold text-foreground">{{ form.invoice_number || '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Commande</dt><dd class="font-semibold text-foreground">{{ selectedOrder?.order_number || (form.purchase_order_uuid ? 'Liée' : 'Aucune') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Détail</dt><dd class="font-semibold tabular-nums text-foreground">{{ detailed ? `${readyLines} / ${form.lines.length} lignes` : 'Montant global' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Document</dt><dd :class="['font-semibold', form.attachment || invoice?.has_attachment ? 'text-emerald-600' : 'text-muted-foreground']">{{ form.attachment ? 'À joindre' : (invoice?.has_attachment ? 'Déjà joint' : 'Aucun') }}</dd></div>
                </dl>
                <div class="mt-4 rounded-lg bg-primary/10 px-4 py-3">
                    <p class="text-xs font-semibold text-primary">Total de la facture</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums text-primary">{{ formatMoney(total) }}</p>
                </div>
                <div class="mt-4 flex flex-col gap-2">
                    <Button type="submit" size="lg" class="w-full justify-center" :disabled="form.processing || !canSubmit">
                        <Save class="h-4 w-4" />{{ form.processing ? 'Enregistrement…' : (invoice ? 'Enregistrer les modifications' : 'Enregistrer la facture') }}
                    </Button>
                    <Button :as="Link" :href="cancelHref" size="lg" variant="white-outline" class="w-full justify-center">Annuler</Button>
                </div>
            </section>
            <p class="flex items-start gap-2 px-1 text-xs text-muted-foreground"><Info class="mt-0.5 h-4 w-4" />Une facture est une pièce comptable : elle ne modifie jamais le stock. C’est la réception qui fait entrer la marchandise.</p>
        </aside>
    </form>
</template>
