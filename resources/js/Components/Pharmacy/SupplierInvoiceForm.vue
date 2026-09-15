<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import FormSection from '@/Components/UI/FormSection.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatMoney } from '@/utilities/pharmacyStatus';

/**
 * ADR-098 — the supplier invoice form, shared by the clinic and the central
 * portal. When the page provides the supplier's orders, the invoice can be
 * tied to an order (and its reception) and start from its lines.
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
    invoice_date: initial?.invoice_date ?? new Date().toISOString().slice(0, 10),
    purchase_order_uuid: initial
        ? (initial.purchase_order_uuid ?? '')
        : (props.orders.some((order) => order.uuid === props.initialOrderUuid) ? props.initialOrderUuid : ''),
    goods_receipt_uuid: initial?.goods_receipt_uuid ?? '',
    notes: initial?.notes ?? '',
    attachment: null,
    lines: initial?.lines.length
        ? initial.lines.map((line) => ({ medicine_uuid: line.medicine_uuid, description: line.description, quantity: line.quantity, unit_price: String(line.unit_price) }))
        : [blankLine()],
});

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
};

const addLine = () => form.lines.push(blankLine());
const removeLine = (index) => { if (form.lines.length > 1) form.lines.splice(index, 1); };
const lineTotal = (line) => (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
const total = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));
const readyLines = computed(() => form.lines.filter((line) => line.medicine_uuid && line.description && Number(line.unit_price) > 0).length);

const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 disabled:bg-gray-50 disabled:text-slate-400 dark:border-gray-800 dark:bg-gray-950 dark:text-white dark:disabled:bg-gray-900';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-white';

const submit = () => {
    if (!supplierUuid.value) return;
    form.post(props.submitUrl(supplierUuid.value), { forceFormData: true, preserveScroll: true });
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
                    <label class="block">
                        <span :class="labelClass">Date de la facture <span class="text-red-500">*</span></span>
                        <input v-model="form.invoice_date" type="date" :class="inputClass" required>
                    </label>
                    <label :class="['block', suppliers ? 'md:col-span-3' : '']">
                        <span :class="labelClass">Remarque</span>
                        <input v-model="form.notes" type="text" maxlength="2000" :class="inputClass" placeholder="Ex. remise de 5 % appliquée">
                    </label>
                </div>

                <label class="mt-4 flex cursor-pointer items-center gap-4 rounded-xl border-2 border-dashed border-gray-200 px-4 py-4 transition hover:border-primary-300 dark:border-gray-800">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-xl text-slate-500 dark:bg-gray-900"><Icon :name="form.attachment ? 'file-check' : 'upload-cloud'" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-slate-700 dark:text-white">{{ form.attachment ? form.attachment.name : (invoice?.has_attachment ? 'Remplacer le document (facultatif)' : 'Joindre la facture (facultatif)') }}</span>
                        <span class="block text-xs text-slate-400">PDF, photo ou Excel — 10 Mo au maximum</span>
                    </span>
                    <span class="text-sm font-semibold text-primary-600">Choisir</span>
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
                <Button v-if="selectedOrder?.lines.length" type="button" size="rg" variant="white-outline" class="mt-4" @click="copyOrderLines"><Icon name="copy" /><span class="ms-2">Reprendre les lignes de {{ selectedOrder.order_number }}</span></Button>
            </FormSection>

            <FormSection icon="list" title="Lignes de la facture" description="Une ligne par produit facturé ; le libellé est celui écrit sur la facture.">
                <template #actions>
                    <Button type="button" size="rg" variant="white-outline" @click="addLine"><Icon name="plus" /><span class="ms-2">Ajouter une ligne</span></Button>
                </template>

                <div class="space-y-3">
                    <div v-for="(line, index) in form.lines" :key="index" class="rounded-xl border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-900/30">
                        <div class="grid items-end gap-3 lg:grid-cols-[32px_minmax(0,1fr)_minmax(0,1fr)_90px_140px_120px_40px]">
                            <span class="hidden h-11 items-center justify-center text-sm font-bold text-slate-400 lg:flex">{{ index + 1 }}</span>
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
                                <span :class="labelClass">Prix unitaire</span>
                                <span class="relative block">
                                    <input v-model="line.unit_price" type="number" min="0.01" step="0.01" :class="[inputClass, 'pe-14 text-end']" required>
                                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-slate-400">MGA</span>
                                </span>
                            </label>
                            <div class="text-end">
                                <span :class="labelClass">Sous-total</span>
                                <p class="flex h-11 items-center justify-end font-bold tabular-nums text-slate-800 dark:text-white">{{ formatMoney(lineTotal(line)) }}</p>
                            </div>
                            <button type="button" class="flex h-11 w-10 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30 dark:hover:bg-red-950/30" :disabled="form.lines.length <= 1" :aria-label="`Retirer la ligne ${index + 1}`" @click="removeLine(index)"><Icon name="trash" /></button>
                        </div>
                    </div>
                </div>

                <button type="button" class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-200 py-3 text-sm font-semibold text-slate-500 transition hover:border-primary-300 hover:text-primary-600 dark:border-gray-800" @click="addLine">
                    <Icon name="plus" />Ajouter une ligne
                </button>
            </FormSection>
        </div>

        <aside class="space-y-3 xl:sticky xl:top-20">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                <h2 class="font-heading text-base font-bold text-slate-800 dark:text-white">Récapitulatif</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Fournisseur</dt><dd class="truncate text-end font-semibold text-slate-800 dark:text-white">{{ supplierLabel || '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Facture</dt><dd class="truncate text-end font-mono font-semibold text-slate-800 dark:text-white">{{ form.invoice_number || '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Commande</dt><dd class="font-semibold text-slate-800 dark:text-white">{{ selectedOrder?.order_number || (form.purchase_order_uuid ? 'Liée' : 'Aucune') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Lignes complètes</dt><dd class="font-semibold tabular-nums text-slate-800 dark:text-white">{{ readyLines }} / {{ form.lines.length }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Document</dt><dd :class="['font-semibold', form.attachment || invoice?.has_attachment ? 'text-emerald-600' : 'text-slate-400']">{{ form.attachment ? 'À joindre' : (invoice?.has_attachment ? 'Déjà joint' : 'Aucun') }}</dd></div>
                </dl>
                <div class="mt-4 rounded-lg bg-primary-50 px-4 py-3 dark:bg-primary-950/30">
                    <p class="text-xs font-semibold text-primary-700 dark:text-primary-300">Total de la facture</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums text-primary-800 dark:text-white">{{ formatMoney(total) }}</p>
                </div>
                <div class="mt-4 flex flex-col gap-2">
                    <Button type="submit" size="lg" class="w-full justify-center" :disabled="form.processing || !supplierUuid || !form.invoice_number || !readyLines">
                        <Icon name="save" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : (invoice ? 'Enregistrer les modifications' : 'Enregistrer la facture') }}</span>
                    </Button>
                    <Button :as="Link" :href="cancelHref" size="lg" variant="white-outline" class="w-full justify-center">Annuler</Button>
                </div>
            </section>
            <p class="flex items-start gap-2 px-1 text-xs text-slate-500"><Icon name="info" class="mt-0.5" />Une facture est une pièce comptable : elle ne modifie jamais le stock. C’est la réception qui fait entrer la marchandise.</p>
        </aside>
    </form>
</template>
