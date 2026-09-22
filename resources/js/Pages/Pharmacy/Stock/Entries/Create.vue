<script setup>
import DatePicker from '@/Components/Shadcn/DatePicker.vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/UI/Breadcrumb.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney, formatNumber } from '@/utilities/pharmacyStatus';

defineOptions({ layout: AppLayout });

/*
 * ADR-098 — a delivery is entered once (supplier, date, origin), then its
 * medicines are added one by one into a list the pharmacist can re-read,
 * correct or remove before recording everything in a single step.
 */
const props = defineProps({
    capabilities: { type: Object, required: true },
    medicines: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
});

const page = usePage();
const query = new URLSearchParams(page.url.split('?')[1] ?? '');

const form = useForm({
    supplier_uuid: '',
    received_at: new Date().toISOString().slice(0, 10),
    origin: '',
    destination: `Stock Pharmacie — ${page.props.site?.name || page.props.site?.code || ''}`,
    reason: '',
    entries: [],
});

const blankLine = () => ({ medicine_uuid: '', operation: 'ENTREE', lot_number: '', expires_at: '', quantity: 1, unit_purchase_price: '' });
const draft = ref({ ...blankLine(), medicine_uuid: query.get('medicine') ?? '' });
const editingIndex = ref(null);
const showLineForm = ref(true);
const lineError = ref('');
const lineFormRef = ref(null);

const medicineByUuid = computed(() => Object.fromEntries(props.medicines.map((medicine) => [medicine.uuid, medicine])));
const selectedSupplier = computed(() => props.suppliers.find((supplier) => supplier.uuid === form.supplier_uuid) ?? null);
const availableMedicines = computed(() => (form.supplier_uuid
    ? props.medicines.filter((medicine) => (medicine.supplier_uuids ?? []).includes(form.supplier_uuid))
    : props.medicines));
const selectedMedicine = computed(() => medicineByUuid.value[draft.value.medicine_uuid] ?? null);
const existingLot = computed(() => selectedMedicine.value?.lots.find(
    (lot) => lot.lot_number.toLocaleLowerCase() === draft.value.lot_number.trim().toLocaleLowerCase(),
));

watch(() => form.supplier_uuid, () => {
    if (draft.value.medicine_uuid && !availableMedicines.value.some((medicine) => medicine.uuid === draft.value.medicine_uuid)) {
        draft.value.medicine_uuid = '';
    }
});

// A known lot already has its expiry date: fill it in rather than let the
// pharmacist type a different one that the server would refuse.
watch(existingLot, (lot) => {
    if (lot?.expires_at) draft.value.expires_at = lot.expires_at;
});

// The supplier's current price fills the purchase price, never replacing one typed.
const quotedPrice = computed(() => (form.supplier_uuid ? selectedMedicine.value?.supplier_prices?.[form.supplier_uuid] ?? null : null));
let autoFilledPrice = null;
watch(quotedPrice, (price) => {
    if (!props.capabilities.can_record_cost) return;
    if (draft.value.unit_purchase_price === '' || draft.value.unit_purchase_price === autoFilledPrice) {
        draft.value.unit_purchase_price = price ?? '';
        autoFilledPrice = draft.value.unit_purchase_price;
    }
});

const operations = computed(() => [
    { value: 'ENTREE', title: 'Marchandise reçue', text: 'Ajouter des boîtes à un lot existant ou nouveau.' },
    ...(props.capabilities.can_create_lot
        ? [{ value: 'STOCK_INITIAL', title: 'Stock de départ', text: 'Lot déjà présent à la mise en service.' }]
        : []),
]);
const operationLabel = (value) => operations.value.find((operation) => operation.value === value)?.title ?? value;

const addLine = () => {
    const line = { ...draft.value, lot_number: draft.value.lot_number.trim() };
    lineError.value = '';

    if (!line.medicine_uuid || !line.lot_number || !line.expires_at || !(Number(line.quantity) >= 1)) {
        lineError.value = 'Choisissez le médicament et indiquez le lot, la péremption et une quantité d’au moins 1.';

        return;
    }

    const duplicate = form.entries.findIndex((entry, index) => index !== editingIndex.value
        && entry.medicine_uuid === line.medicine_uuid
        && entry.lot_number.toLocaleLowerCase() === line.lot_number.toLocaleLowerCase());

    if (duplicate !== -1) {
        lineError.value = `Ce lot est déjà dans la liste (ligne ${duplicate + 1}) : modifiez cette ligne.`;

        return;
    }

    if (editingIndex.value === null) {
        form.entries.push(line);
    } else {
        form.entries.splice(editingIndex.value, 1, line);
    }

    form.clearErrors();
    draft.value = blankLine();
    autoFilledPrice = null;
    editingIndex.value = null;
    showLineForm.value = false;
};

const openLineForm = async () => {
    draft.value = blankLine();
    editingIndex.value = null;
    showLineForm.value = true;
    await nextTick();
    lineFormRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const editLine = async (index) => {
    draft.value = { ...form.entries[index] };
    autoFilledPrice = null;
    editingIndex.value = index;
    showLineForm.value = true;
    lineError.value = '';
    await nextTick();
    lineFormRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const cancelEdit = () => {
    draft.value = blankLine();
    editingIndex.value = null;
    lineError.value = '';
    showLineForm.value = form.entries.length === 0;
};

const removeLine = (index) => {
    if (!confirm('Retirer cette ligne de la liste ?')) return;
    form.entries.splice(index, 1);
    if (editingIndex.value === index) cancelEdit();
    if (!form.entries.length) showLineForm.value = true;
};

const lineErrors = (index) => Object.entries(form.errors)
    .filter(([key]) => key.startsWith(`entries.${index}.`))
    .map(([, message]) => message);

const totalQuantity = computed(() => form.entries.reduce((sum, entry) => sum + (Number(entry.quantity) || 0), 0));
const totalValue = computed(() => form.entries.reduce((sum, entry) => sum + (Number(entry.quantity) || 0) * (Number(entry.unit_purchase_price) || 0), 0));

const deliveryReady = computed(() => form.origin.trim() && form.destination.trim() && form.reason.trim().length >= 3);
const submit = () => {
    if (!form.entries.length) return;
    if (!confirm(`Enregistrer ${form.entries.length} entrée${form.entries.length > 1 ? 's' : ''} de stock ? Une entrée enregistrée ne se modifie plus : une erreur se corrige ensuite par une correction de stock.`)) return;
    form.post('/pharmacy/stock/entries/batch', { preserveScroll: true });
};

const inputClass = 'h-11 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-100 dark:border-gray-800 dark:bg-gray-950 dark:text-white';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-white';
const stepClass = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-sm font-bold text-primary-600 dark:bg-primary-950/40 dark:text-primary-300';
</script>

<template>
    <Head title="Enregistrer une entrée de stock" />

    <div class="w-full space-y-5">
        <Breadcrumb :items="[{ label: 'Médicaments & stock', href: '/pharmacy/stock' }, { label: 'Enregistrer une entrée' }]" />

        <div>
            <h1 class="font-heading text-2xl font-bold text-slate-800 dark:text-white">Enregistrer une entrée de stock</h1>
            <p class="mt-1 text-sm text-slate-500">Décrivez la livraison, ajoutez ses médicaments un par un, vérifiez la liste, puis enregistrez tout en une fois.</p>
        </div>

        <section v-if="!medicines.length" class="rounded-xl border border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950">
            <EmptyState icon="package" title="Aucun médicament actif" description="Un médicament doit d’abord exister dans le catalogue de la clinique." />
        </section>

        <template v-else>
            <ValidationErrorSummary :errors="form.errors" />

            <div class="grid items-start gap-5 xl:grid-cols-5">
                <div class="space-y-5 xl:col-span-2">
                    <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-900 dark:bg-gray-950">
                        <h2 class="flex items-center gap-3 font-heading text-base font-bold text-slate-800 dark:text-white"><span :class="stepClass">1</span>La livraison</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <label v-if="capabilities.can_view_suppliers" class="block sm:col-span-2">
                                <span :class="labelClass">Fournisseur</span>
                                <select v-model="form.supplier_uuid" name="supplier_uuid" :class="inputClass">
                                    <option value="">Tous les fournisseurs (aucun fournisseur enregistré)</option>
                                    <option v-for="supplier in suppliers" :key="supplier.uuid" :value="supplier.uuid">{{ supplier.name }}</option>
                                </select>
                                <span class="mt-1 block text-xs text-slate-500">
                                    <template v-if="selectedSupplier">Seuls les {{ availableMedicines.length }} médicament{{ availableMedicines.length > 1 ? 's' : '' }} de {{ selectedSupplier.name }} sont proposés.</template>
                                    <template v-else>Choisissez le fournisseur pour ne voir que ses médicaments. Il s’applique à toute la liste.</template>
                                </span>
                            </label>
                            <label class="block">
                                <span :class="labelClass">Date de réception</span>
                                <DatePicker v-model="form.received_at" name="received_at" />
                            </label>
                            <label class="block">
                                <span :class="labelClass">Provenance <span class="text-red-500">*</span></span>
                                <input v-model="form.origin" name="origin" type="text" maxlength="150" :class="inputClass" placeholder="Ex. bon de livraison BL-0142">
                            </label>
                            <label class="block sm:col-span-2">
                                <span :class="labelClass">Rangé dans <span class="text-red-500">*</span></span>
                                <input v-model="form.destination" name="destination" type="text" maxlength="150" :class="inputClass">
                            </label>
                            <label class="block sm:col-span-2">
                                <span :class="labelClass">Motif <span class="text-red-500">*</span></span>
                                <input v-model="form.reason" name="reason" type="text" maxlength="2000" :class="inputClass" placeholder="Ex. livraison de la commande BC-000012">
                            </label>
                        </div>
                    </section>

                    <section v-if="showLineForm" ref="lineFormRef" class="rounded-xl border border-primary-200 bg-white p-5 shadow-sm dark:border-primary-900 dark:bg-gray-950">
                        <h2 class="flex items-center gap-3 font-heading text-base font-bold text-slate-800 dark:text-white">
                            <span :class="stepClass">2</span>{{ editingIndex === null ? 'Ajouter un médicament' : `Modifier la ligne ${editingIndex + 1}` }}
                        </h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div v-if="selectedSupplier && !availableMedicines.length" class="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100 sm:col-span-2">
                                <span>Aucun médicament n’est encore rattaché à {{ selectedSupplier.name }}.</span>
                                <button type="button" class="self-start text-sm font-bold text-primary-700 hover:underline dark:text-primary-300" @click="form.supplier_uuid = ''">Afficher tous les médicaments</button>
                            </div>
                            <label class="block sm:col-span-2">
                                <span :class="labelClass">Médicament <span class="text-red-500">*</span></span>
                                <select v-model="draft.medicine_uuid" :class="inputClass" :disabled="!availableMedicines.length">
                                    <option value="">Choisir un médicament</option>
                                    <option v-for="medicine in availableMedicines" :key="medicine.uuid" :value="medicine.uuid">{{ medicine.name }} · {{ medicine.code }}</option>
                                </select>
                            </label>
                            <fieldset v-if="operations.length > 1" class="sm:col-span-2">
                                <legend :class="labelClass">Type d’entrée</legend>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label v-for="operation in operations" :key="operation.value" :class="['flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 transition', draft.operation === operation.value ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-950/20' : 'border-gray-200 dark:border-gray-800']">
                                        <input v-model="draft.operation" type="radio" :value="operation.value" class="mt-1">
                                        <span><span class="block text-sm font-semibold text-slate-800 dark:text-white">{{ operation.title }}</span><span class="block text-xs text-slate-500">{{ operation.text }}</span></span>
                                    </label>
                                </div>
                            </fieldset>
                            <label class="block">
                                <span :class="labelClass">Numéro de lot <span class="text-red-500">*</span></span>
                                <input v-model="draft.lot_number" type="text" maxlength="100" list="existing-lots" :class="inputClass">
                                <datalist id="existing-lots"><option v-for="lot in selectedMedicine?.lots ?? []" :key="lot.uuid" :value="lot.lot_number" /></datalist>
                                <span v-if="existingLot" class="mt-1 block text-xs text-emerald-600">Lot déjà connu : la quantité s’y ajoutera.</span>
                            </label>
                            <label class="block">
                                <span :class="labelClass">Péremption <span class="text-red-500">*</span></span>
                                <DatePicker v-model="draft.expires_at" :readonly="Boolean(existingLot)" />
                            </label>
                            <label class="block">
                                <span :class="labelClass">Quantité reçue <span class="text-red-500">*</span></span>
                                <span class="relative block">
                                    <input v-model.number="draft.quantity" type="number" min="1" step="1" :class="[inputClass, 'pe-20']">
                                    <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-slate-400">{{ selectedMedicine?.unit ?? 'unités' }}</span>
                                </span>
                            </label>
                            <label v-if="capabilities.can_record_cost" class="block">
                                <span :class="labelClass">Prix d’achat unitaire (MGA)</span>
                                <input v-model="draft.unit_purchase_price" type="number" min="0" step="0.01" :class="inputClass">
                                <span v-if="quotedPrice" class="mt-1 block text-xs text-slate-500">Prix actuel du fournisseur : {{ quotedPrice }} MGA</span>
                            </label>
                        </div>
                        <p v-if="lineError" class="mt-3 text-sm text-red-600">{{ lineError }}</p>
                        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button v-if="editingIndex !== null || form.entries.length" type="button" size="rg" variant="white-outline" @click="cancelEdit">Annuler</Button>
                            <Button type="button" size="rg" @click="addLine">
                                <Icon :name="editingIndex === null ? 'plus' : 'check'" /><span class="ms-2">{{ editingIndex === null ? 'Ajouter à la liste' : 'Mettre à jour la ligne' }}</span>
                            </Button>
                        </div>
                    </section>

                    <Button v-else type="button" size="lg" variant="white-outline" class="w-full" @click="openLineForm">
                        <Icon name="plus" /><span class="ms-2">Ajouter un autre médicament</span>
                    </Button>
                </div>

                <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-900 dark:bg-gray-950 xl:sticky xl:top-20 xl:col-span-3">
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-5 py-4 dark:border-gray-900">
                        <h2 class="flex items-center gap-3 font-heading text-base font-bold text-slate-800 dark:text-white"><span :class="stepClass">3</span>Vérifier la liste</h2>
                        <span class="text-sm text-slate-500">{{ form.entries.length }} ligne{{ form.entries.length > 1 ? 's' : '' }} · {{ formatNumber(totalQuantity) }} unité{{ totalQuantity > 1 ? 's' : '' }}</span>
                    </header>
                    <div v-if="form.entries.length" class="overflow-x-auto">
                        <table class="w-full min-w-[720px] text-sm">
                            <thead class="bg-gray-50 text-xs font-semibold text-slate-500 dark:bg-gray-1000">
                                <tr>
                                    <th class="px-4 py-3 text-start">#</th>
                                    <th class="px-4 py-3 text-start">Médicament</th>
                                    <th class="px-4 py-3 text-start">Lot</th>
                                    <th class="px-4 py-3 text-start">Péremption</th>
                                    <th class="px-4 py-3 text-end">Quantité</th>
                                    <th v-if="capabilities.can_record_cost" class="px-4 py-3 text-end">Prix unitaire</th>
                                    <th class="px-4 py-3 text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-900">
                                <template v-for="(entry, index) in form.entries" :key="`${entry.medicine_uuid}-${entry.lot_number}`">
                                    <tr :class="[editingIndex === index && 'bg-primary-50/50 dark:bg-primary-950/20', lineErrors(index).length && 'bg-red-50/60 dark:bg-red-950/10']">
                                        <td class="px-4 py-3 text-slate-400">{{ index + 1 }}</td>
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-slate-800 dark:text-white">{{ medicineByUuid[entry.medicine_uuid]?.name ?? '—' }}</p>
                                            <p class="text-xs text-slate-400">{{ operationLabel(entry.operation) }}</p>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-slate-700 dark:text-slate-200">{{ entry.lot_number }}</td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ formatDate(entry.expires_at) }}</td>
                                        <td class="px-4 py-3 text-end font-semibold tabular-nums">{{ formatNumber(entry.quantity) }}</td>
                                        <td v-if="capabilities.can_record_cost" class="px-4 py-3 text-end tabular-nums">{{ entry.unit_purchase_price !== '' ? formatMoney(entry.unit_purchase_price) : '—' }}</td>
                                        <td class="px-4 py-3">
                                            <div class="flex justify-end gap-1.5">
                                                <Button size="sm" variant="white-outline" type="button" @click="editLine(index)">Modifier</Button>
                                                <Button size="sm" variant="white-outline" type="button" class="text-red-600" @click="removeLine(index)">Retirer</Button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="lineErrors(index).length">
                                        <td colspan="7" class="bg-red-50/60 px-4 pb-3 text-xs text-red-700 dark:bg-red-950/10 dark:text-red-300">{{ lineErrors(index).join(' ') }}</td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot v-if="capabilities.can_record_cost && totalValue">
                                <tr class="border-t border-gray-200 dark:border-gray-900">
                                    <td colspan="5" class="px-4 py-3 text-end text-sm text-slate-500">Valeur d’achat de la livraison</td>
                                    <td class="px-4 py-3 text-end font-bold tabular-nums">{{ formatMoney(totalValue) }}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <EmptyState v-else icon="list-check" title="La liste est vide" description="Ajoutez le premier médicament de la livraison : il apparaîtra ici pour être vérifié." />

                    <footer class="flex flex-col gap-3 border-t border-gray-200 px-5 py-4 dark:border-gray-900 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-500">Rien n’est enregistré tant que vous n’avez pas confirmé. Si une ligne est refusée, aucune ne l’est : corrigez-la et recommencez.</p>
                        <div class="flex shrink-0 gap-2">
                            <Button :as="Link" href="/pharmacy/stock" size="lg" variant="white-outline">Annuler</Button>
                            <Button type="button" size="lg" :disabled="form.processing || !form.entries.length || !deliveryReady" :title="deliveryReady ? '' : 'Complétez la provenance, le rangement et le motif de la livraison.'" @click="submit">
                                <Icon name="save" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : `Enregistrer ${form.entries.length || ''} entrée${form.entries.length > 1 ? 's' : ''}` }}</span>
                            </Button>
                        </div>
                    </footer>
                </section>
            </div>
        </template>
    </div>
</template>
