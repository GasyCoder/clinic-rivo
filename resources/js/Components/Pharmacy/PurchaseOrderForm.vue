<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import FormSection from '@/Components/UI/FormSection.vue';
import Icon from '@/Components/UI/Icon.vue';
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
    // Each medicine may carry this supplier's current `quoted_price`.
    medicines: { type: Array, default: () => [] },
    submitUrl: { type: Function, required: true },
    cancelHref: { type: String, required: true },
    // A draft being corrected: its lines fill the form and it is sent with PUT.
    order: { type: Object, default: null },
});

const blankLine = () => ({ medicine_uuid: '', quantity_ordered: 1, unit_price: '' });

const form = useForm({
    supplier_uuid: props.supplierUuid,
    expected_delivery_at: props.order?.expected_delivery_at ?? '',
    notes: props.order?.notes ?? '',
    lines: props.order?.lines.length
        ? props.order.lines.map((line) => ({ medicine_uuid: line.medicine_uuid, quantity_ordered: line.quantity_ordered, unit_price: String(line.unit_price) }))
        : [blankLine()],
});

const medicineByUuid = computed(() => Object.fromEntries(props.medicines.map((medicine) => [medicine.uuid, medicine])));
const pricedCount = computed(() => props.medicines.filter((medicine) => medicine.quoted_price).length);
const supplierLabel = computed(() => props.supplierName || props.suppliers?.find((supplier) => supplier.uuid === form.supplier_uuid)?.name || '');

// The supplier's current price fills an empty price, never one already typed.
const onMedicineChange = (line) => {
    const price = medicineByUuid.value[line.medicine_uuid]?.quoted_price;
    if (price && line.unit_price === '') line.unit_price = price;
};

const addLine = () => form.lines.push(blankLine());
const removeLine = (index) => { if (form.lines.length > 1) form.lines.splice(index, 1); };
const step = (line, delta) => { line.quantity_ordered = Math.max(1, (Number(line.quantity_ordered) || 1) + delta); };
const lineTotal = (line) => (Number(line.quantity_ordered) || 0) * (Number(line.unit_price) || 0);
const total = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));
const units = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.quantity_ordered) || 0), 0));
const readyLines = computed(() => form.lines.filter((line) => line.medicine_uuid && Number(line.unit_price) > 0).length);

const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-white';

const submit = () => {
    if (!form.supplier_uuid) return;
    const transformed = form.transform(({ supplier_uuid, ...data }) => data);
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
                        <select v-model="form.supplier_uuid" :class="inputClass" required>
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

            <FormSection icon="capsule" title="Médicaments commandés">
                <template #description>
                    <template v-if="pricedCount">{{ pricedCount }} médicament{{ pricedCount > 1 ? 's' : '' }} avec un prix fournisseur, en tête de liste : le prix se remplit tout seul.</template>
                    <template v-else>Choisissez le médicament, la quantité et le prix unitaire.</template>
                </template>
                <template #actions>
                    <Button type="button" size="rg" variant="white-outline" @click="addLine"><Icon name="plus" /><span class="ms-2">Ajouter un médicament</span></Button>
                </template>

                <div class="space-y-3">
                    <div v-for="(line, index) in form.lines" :key="index" class="rounded-xl border border-gray-200 bg-gray-50/50 p-4 dark:border-gray-800 dark:bg-gray-900/30">
                        <div class="grid items-end gap-3 lg:grid-cols-[32px_minmax(0,1fr)_150px_160px_130px_40px]">
                            <span class="hidden h-11 items-center justify-center rounded-full text-sm font-bold text-slate-400 lg:flex">{{ index + 1 }}</span>
                            <label class="block">
                                <span :class="labelClass">Médicament</span>
                                <select v-model="line.medicine_uuid" :class="inputClass" required @change="onMedicineChange(line)">
                                    <option value="">Choisir un médicament</option>
                                    <option v-for="medicine in medicines" :key="medicine.uuid" :value="medicine.uuid">{{ medicine.name }} · {{ medicine.code }}{{ medicine.quoted_price ? ` — ${formatMoney(medicine.quoted_price)}` : '' }}</option>
                                </select>
                            </label>
                            <div>
                                <span :class="labelClass">Quantité</span>
                                <div class="flex h-11 overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
                                    <button type="button" class="w-10 text-lg text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900" :aria-label="`Diminuer la ligne ${index + 1}`" @click="step(line, -1)">−</button>
                                    <input v-model.number="line.quantity_ordered" type="number" min="1" class="w-full min-w-0 border-0 bg-transparent text-center text-sm font-semibold text-slate-700 outline-none dark:text-white" required>
                                    <button type="button" class="w-10 text-lg text-slate-500 hover:bg-gray-100 dark:hover:bg-gray-900" :aria-label="`Augmenter la ligne ${index + 1}`" @click="step(line, 1)">+</button>
                                </div>
                            </div>
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
                        <p v-if="medicineByUuid[line.medicine_uuid]?.quoted_price && String(line.unit_price) !== String(medicineByUuid[line.medicine_uuid].quoted_price)" class="mt-2 text-xs text-amber-600">
                            Le prix du fournisseur est {{ formatMoney(medicineByUuid[line.medicine_uuid].quoted_price) }}.
                        </p>
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
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Lignes complètes</dt><dd class="font-semibold tabular-nums text-slate-800 dark:text-white">{{ readyLines }} / {{ form.lines.length }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Unités</dt><dd class="font-semibold tabular-nums text-slate-800 dark:text-white">{{ units }}</dd></div>
                </dl>
                <div class="mt-4 rounded-lg bg-primary-50 px-4 py-3 dark:bg-primary-950/30">
                    <p class="text-xs font-semibold text-primary-700 dark:text-primary-300">Total estimé</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums text-primary-800 dark:text-white">{{ formatMoney(total) }}</p>
                </div>
                <div class="mt-4 flex flex-col gap-2">
                    <Button type="submit" size="lg" class="w-full justify-center" :disabled="form.processing || !form.supplier_uuid || !readyLines">
                        <Icon name="save" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : (order ? 'Enregistrer les modifications' : 'Enregistrer le brouillon') }}</span>
                    </Button>
                    <Button :as="Link" :href="cancelHref" size="lg" variant="white-outline" class="w-full justify-center">Annuler</Button>
                </div>
            </section>
            <p class="flex items-start gap-2 px-1 text-xs text-slate-500"><Icon name="info" class="mt-0.5" />Le brouillon n’engage rien : vous l’enverrez au fournisseur après vérification. Une commande ne modifie jamais le stock.</p>
        </aside>
    </form>
</template>
