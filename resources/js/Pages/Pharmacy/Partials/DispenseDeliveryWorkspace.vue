<script setup>
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';

const props = defineProps({
    target: { type: Object, default: null },
    form: { type: Object, required: true },
    canViewLots: { type: Boolean, default: false },
    canViewExpiration: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'submit']);

const deliveryLines = computed(() => (props.target?.lines ?? [])
    .filter((line) => line.remaining_quantity > 0)
    .map((line) => ({
        details: line,
        formLine: props.form.lines.find((item) => item.uuid === line.uuid),
    }))
    .filter((item) => item.formLine));
const selectedQuantity = computed(() => deliveryLines.value.reduce(
    (total, item) => total + Math.max(0, Number(item.formLine.quantity || 0)),
    0,
));
const remainingBefore = computed(() => deliveryLines.value.reduce(
    (total, item) => total + Number(item.details.remaining_quantity || 0),
    0,
));
const remainingAfter = computed(() => Math.max(0, remainingBefore.value - selectedQuantity.value));
const isComplete = computed(() => selectedQuantity.value === remainingBefore.value && selectedQuantity.value > 0);

const clampQuantity = (item) => {
    const value = Math.trunc(Number(item.formLine.quantity || 0));
    item.formLine.quantity = Math.min(Number(item.details.remaining_quantity), Math.max(0, value));
};

const changeQuantity = (item, delta) => {
    item.formLine.quantity = Math.min(
        Number(item.details.remaining_quantity),
        Math.max(0, Number(item.formLine.quantity || 0) + delta),
    );
};

const selectAll = () => deliveryLines.value.forEach((item) => {
    item.formLine.quantity = item.details.remaining_quantity;
});

const clearAll = () => deliveryLines.value.forEach((item) => {
    item.formLine.quantity = 0;
});

const plannedLots = (item) => {
    let remaining = Number(item.formLine.quantity || 0);

    return (item.details.lots ?? []).map((lot) => {
        const quantity = Math.min(remaining, Number(lot.quantity || 0));
        remaining -= quantity;
        return { ...lot, planned_quantity: quantity };
    }).filter((lot) => lot.planned_quantity > 0);
};

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`))
    : '—';
const formatMoney = (value) => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(Number(value || 0))} MGA`;
</script>

<template>
    <Teleport to="body">
        <div v-if="target" class="fixed inset-0 z-[1250] flex justify-end bg-slate-950/60 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="delivery-title" @click.self="emit('close')" @keydown.esc="emit('close')">
            <form class="flex h-full w-full max-w-3xl flex-col bg-gray-50 shadow-2xl dark:bg-gray-1000" @submit.prevent="emit('submit')">
                <header class="shrink-0 border-b border-gray-200 bg-white px-4 py-4 dark:border-gray-900 dark:bg-gray-950 sm:px-6">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-xl text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-300"><Icon name="package" /></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-relaxed text-emerald-600">Sortie physique autorisée</p>
                                <h2 id="delivery-title" class="truncate font-heading text-lg font-bold text-slate-700 dark:text-white">Délivrer · {{ target.customer_name }}</h2>
                                <p class="mt-0.5 text-xs text-slate-500">{{ target.type === 'EXTERNAL' ? 'Client externe' : 'Patient interne' }} · {{ target.invoice?.number }}</p>
                            </div>
                        </div>
                        <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:hover:text-white" aria-label="Fermer" @click="emit('close')"><Icon class="text-xl" name="cross" /></button>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 dark:border-emerald-900 dark:bg-emerald-950/20">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-emerald-600">Contrôle Caisse</p>
                            <p class="mt-1 flex items-center gap-1.5 text-xs font-bold text-emerald-800 dark:text-emerald-200"><Icon name="check-circle" /> Règlement confirmé</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Facture</p>
                            <p class="mt-1 truncate text-xs font-bold text-slate-700 dark:text-white">{{ target.invoice?.number }} · {{ formatMoney(target.invoice?.total_amount) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Délivrance</p>
                            <p class="mt-1 text-xs font-bold text-slate-700 dark:text-white">{{ isComplete ? 'Complète' : 'Partielle' }} · {{ selectedQuantity }} unité(s)</p>
                        </div>
                    </div>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                    <ValidationErrorSummary class="mb-4" :errors="form.errors" />

                    <section v-if="target.type === 'EXTERNAL'" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
                        <div class="flex items-center gap-2 text-sm font-bold text-emerald-900 dark:text-emerald-100"><Icon name="user" /> Livraison au client externe</div>
                        <div class="mt-2 grid gap-2 text-xs text-emerald-800 dark:text-emerald-200 sm:grid-cols-2">
                            <p><span class="text-emerald-600">Client :</span> {{ target.customer_name }}</p>
                            <p><span class="text-emerald-600">Téléphone :</span> {{ target.customer_phone || 'Non renseigné' }}</p>
                            <p><span class="text-emerald-600">Ordonnance :</span> {{ target.external_prescription_reference || 'Vente libre' }}</p>
                            <p><span class="text-emerald-600">Prescripteur :</span> {{ target.prescriber || 'Non renseigné' }}</p>
                        </div>
                    </section>

                    <section class="mb-4 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-slate-700 dark:text-white">Quantités à remettre maintenant</h3>
                            <p class="mt-1 text-xs text-slate-500">Une ligne à 0 reste en reliquat. Le backend verrouille et recalcule les lots FEFO à la confirmation.</p>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="white-outline" type="button" @click="clearAll">Tout à 0</Button>
                            <Button size="sm" type="button" @click="selectAll">Tout délivrer</Button>
                        </div>
                    </section>

                    <div class="space-y-3">
                        <article v-for="item in deliveryLines" :key="item.details.uuid" :class="['rounded-xl border bg-white p-4 shadow-sm dark:bg-gray-950', Number(item.formLine.quantity) > 0 ? 'border-primary-300 dark:border-primary-900' : 'border-gray-200 opacity-75 dark:border-gray-800']">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-bold text-slate-700 dark:text-white">{{ item.details.medicine_name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ item.details.medicine_code }} · demandé {{ item.details.quantity_requested }} {{ item.details.unit }} · déjà délivré {{ item.details.quantity_dispensed }}</p>
                                    <p class="mt-1 text-xs font-bold text-primary-600">Reliquat disponible : {{ item.details.remaining_quantity }} {{ item.details.unit }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-lg text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" @click="changeQuantity(item, -1)">−</button>
                                    <label class="block">
                                        <span class="sr-only">Quantité délivrée pour {{ item.details.medicine_name }}</span>
                                        <input v-model.number="item.formLine.quantity" :name="`lines.${form.lines.indexOf(item.formLine)}.quantity`" type="number" min="0" :max="item.details.remaining_quantity" class="h-10 w-20 rounded-lg border border-gray-200 bg-white px-2 text-center text-base font-bold text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:text-white" @change="clampQuantity(item)">
                                    </label>
                                    <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-lg text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" @click="changeQuantity(item, 1)">+</button>
                                    <button type="button" class="h-10 rounded-lg border border-primary-200 bg-primary-50 px-3 text-xs font-bold text-primary-700 hover:bg-primary-100 dark:border-primary-900 dark:bg-primary-950/20 dark:text-primary-300" @click="item.formLine.quantity = item.details.remaining_quantity">Max</button>
                                </div>
                            </div>

                            <div v-if="canViewLots && Number(item.formLine.quantity) > 0 && plannedLots(item).length" class="mt-4 rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-gray-900 dark:bg-gray-1000">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Aperçu de prélèvement FEFO</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span v-for="(lot, index) in plannedLots(item)" :key="lot.uuid" class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[11px] text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300"><strong>{{ index + 1 }}. Lot {{ lot.lot_number }}</strong> · {{ lot.planned_quantity }} {{ item.details.unit }}<template v-if="canViewExpiration"> · exp. {{ formatDate(lot.expires_at) }}</template></span>
                                </div>
                            </div>
                        </article>
                    </div>

                    <label class="mt-4 block rounded-xl border border-gray-200 bg-white p-4 text-sm font-medium text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300">
                        Note de délivrance <span class="text-xs font-normal text-slate-400">(facultative)</span>
                        <textarea v-model="form.notes" name="notes" rows="3" maxlength="2000" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Observation utile sur la remise physique" />
                    </label>
                </div>

                <footer class="shrink-0 border-t border-gray-200 bg-white px-4 py-4 dark:border-gray-900 dark:bg-gray-950 sm:px-6">
                    <div class="mb-4 grid grid-cols-3 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <div class="border-e border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-[9px] font-bold uppercase text-slate-400">Avant</p><p class="mt-1 text-sm font-bold text-slate-700 dark:text-white">{{ remainingBefore }}</p></div>
                        <div class="border-e border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-[9px] font-bold uppercase text-slate-400">Délivré maintenant</p><p class="mt-1 text-sm font-bold text-emerald-600">{{ selectedQuantity }}</p></div>
                        <div class="px-3 py-2"><p class="text-[9px] font-bold uppercase text-slate-400">Reliquat après</p><p class="mt-1 text-sm font-bold text-primary-600">{{ remainingAfter }}</p></div>
                    </div>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button variant="white-outline" size="rg" type="button" @click="emit('close')">Annuler</Button>
                        <Button size="rg" type="submit" :disabled="form.processing || selectedQuantity < 1"><Icon name="check-circle" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : (isComplete ? 'Confirmer la délivrance complète' : 'Confirmer la délivrance partielle') }}</span></Button>
                    </div>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
