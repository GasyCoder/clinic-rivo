<script setup>
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import ValidationErrorSummary from '@/Components/UI/ValidationErrorSummary.vue';
import { formatDate } from '@/utilities/date';
import { formatMoney } from '@/utilities/pharmacyStatus';

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

// Mirrors the server's first-expired-first-out order so the pharmacist
// takes the right boxes from the shelf; the server still decides.
const plannedLots = (item) => {
    let remaining = Number(item.formLine.quantity || 0);

    return (item.details.lots ?? []).map((lot) => {
        const quantity = Math.min(remaining, Number(lot.quantity || 0));
        remaining -= quantity;
        return { ...lot, planned_quantity: quantity };
    }).filter((lot) => lot.planned_quantity > 0);
};
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
                                <h2 id="delivery-title" class="truncate font-heading text-xl font-bold text-slate-800 dark:text-white">Remettre les médicaments</h2>
                                <p class="mt-0.5 text-sm text-slate-500">{{ target.customer_name }} · {{ target.type === 'EXTERNAL' ? 'client externe' : 'patient de la clinique' }} · ticket {{ target.invoice?.number }}</p>
                            </div>
                        </div>
                        <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:hover:text-white" aria-label="Fermer" @click="emit('close')"><Icon class="text-xl" name="cross" /></button>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 dark:border-emerald-900 dark:bg-emerald-950/20">
                            <p class="text-xs text-emerald-700 dark:text-emerald-300">Caisse</p>
                            <p class="mt-0.5 flex items-center gap-1.5 text-sm font-semibold text-emerald-800 dark:text-emerald-200"><Icon name="check-circle" />Ticket réglé</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white px-3 py-2.5 dark:border-gray-800 dark:bg-gray-950">
                            <p class="text-xs text-slate-500">Montant du ticket</p>
                            <p class="mt-0.5 truncate text-sm font-semibold text-slate-800 dark:text-white">{{ formatMoney(target.invoice?.total_amount) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white px-3 py-2.5 dark:border-gray-800 dark:bg-gray-950">
                            <p class="text-xs text-slate-500">Cette fois-ci</p>
                            <p class="mt-0.5 text-sm font-semibold text-slate-800 dark:text-white">{{ isComplete ? 'Tout est remis' : 'Remise en partie' }} · {{ selectedQuantity }} unité{{ selectedQuantity > 1 ? 's' : '' }}</p>
                        </div>
                    </div>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                    <ValidationErrorSummary class="mb-4" :errors="form.errors" />

                    <section v-if="target.type === 'EXTERNAL'" class="mb-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-white"><Icon name="user" />Client</h3>
                        <dl class="mt-2 grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-2">
                            <div><dt class="inline text-slate-500">Nom : </dt><dd class="inline text-slate-800 dark:text-white">{{ target.customer_name }}</dd></div>
                            <div><dt class="inline text-slate-500">Téléphone : </dt><dd class="inline text-slate-800 dark:text-white">{{ target.customer_phone || 'Non renseigné' }}</dd></div>
                            <div><dt class="inline text-slate-500">Prescripteur : </dt><dd class="inline text-slate-800 dark:text-white">{{ target.prescriber || 'Non renseigné' }}</dd></div>
                        </dl>
                    </section>

                    <section class="mb-4 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-950 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 dark:text-white">Combien remettez-vous maintenant ?</h3>
                            <p class="mt-1 text-sm text-slate-500">Laissez 0 pour ce qui sera remis plus tard.</p>
                        </div>
                        <div class="flex gap-2">
                            <Button size="rg" variant="white-outline" type="button" @click="clearAll">Tout à 0</Button>
                            <Button size="rg" type="button" @click="selectAll">Tout remettre</Button>
                        </div>
                    </section>

                    <div class="space-y-3">
                        <article v-for="item in deliveryLines" :key="item.details.uuid" :class="['rounded-xl border bg-white p-4 shadow-sm dark:bg-gray-950', Number(item.formLine.quantity) > 0 ? 'border-primary-300 dark:border-primary-900' : 'border-gray-200 opacity-80 dark:border-gray-800']">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-base font-semibold text-slate-800 dark:text-white">{{ item.details.medicine_name }}</p>
                                    <p class="mt-0.5 text-sm text-slate-500">Demandé {{ item.details.quantity_requested }} {{ item.details.unit }}<span v-if="item.details.quantity_dispensed"> · déjà remis {{ item.details.quantity_dispensed }}</span></p>
                                    <p class="mt-0.5 text-sm font-semibold text-primary-600">Reste à remettre : {{ item.details.remaining_quantity }} {{ item.details.unit }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 text-xl text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" :aria-label="`Retirer une unité de ${item.details.medicine_name}`" @click="changeQuantity(item, -1)">−</button>
                                    <label class="block">
                                        <span class="sr-only">Quantité remise pour {{ item.details.medicine_name }}</span>
                                        <input v-model.number="item.formLine.quantity" :name="`lines.${form.lines.indexOf(item.formLine)}.quantity`" type="number" min="0" :max="item.details.remaining_quantity" class="h-11 w-20 rounded-lg border border-gray-200 bg-white px-2 text-center text-lg font-bold text-slate-800 dark:border-gray-800 dark:bg-gray-950 dark:text-white" @change="clampQuantity(item)">
                                    </label>
                                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-200 text-xl text-slate-600 hover:bg-gray-50 dark:border-gray-800 dark:text-slate-300 dark:hover:bg-gray-900" :aria-label="`Ajouter une unité de ${item.details.medicine_name}`" @click="changeQuantity(item, 1)">+</button>
                                </div>
                            </div>

                            <div v-if="canViewLots && Number(item.formLine.quantity) > 0 && plannedLots(item).length" class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-1000">
                                <p class="text-sm text-slate-500">Boîtes à prendre sur l’étagère, en commençant par celles qui périment le plus tôt :</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span v-for="(lot, index) in plannedLots(item)" :key="lot.uuid" class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-slate-600 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-300"><strong>{{ index + 1 }}. Lot {{ lot.lot_number }}</strong> · {{ lot.planned_quantity }} {{ item.details.unit }}<template v-if="canViewExpiration && lot.expires_at"> · périme le {{ formatDate(lot.expires_at) }}</template></span>
                                </div>
                            </div>
                        </article>
                    </div>

                    <label class="mt-4 block rounded-xl border border-gray-200 bg-white p-4 text-sm font-medium text-slate-700 dark:border-gray-800 dark:bg-gray-950 dark:text-slate-200">
                        Remarque <span class="font-normal text-slate-400">(facultative)</span>
                        <textarea v-model="form.notes" name="notes" rows="2" maxlength="2000" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" placeholder="Ex. le client repassera pour le reste" />
                    </label>
                </div>

                <footer class="shrink-0 border-t border-gray-200 bg-white px-4 py-4 dark:border-gray-900 dark:bg-gray-950 sm:px-6">
                    <div class="mb-4 grid grid-cols-3 overflow-hidden rounded-lg border border-gray-200 text-center dark:border-gray-800">
                        <div class="border-e border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-xs text-slate-500">À remettre</p><p class="mt-0.5 text-lg font-bold text-slate-800 dark:text-white">{{ remainingBefore }}</p></div>
                        <div class="border-e border-gray-200 px-3 py-2 dark:border-gray-800"><p class="text-xs text-slate-500">Remis maintenant</p><p class="mt-0.5 text-lg font-bold text-emerald-600">{{ selectedQuantity }}</p></div>
                        <div class="px-3 py-2"><p class="text-xs text-slate-500">Restera</p><p class="mt-0.5 text-lg font-bold text-primary-600">{{ remainingAfter }}</p></div>
                    </div>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button variant="white-outline" size="lg" type="button" @click="emit('close')">Annuler</Button>
                        <Button size="lg" type="submit" :disabled="form.processing || selectedQuantity < 1"><Icon name="check-circle" /><span class="ms-2">{{ form.processing ? 'Enregistrement…' : 'Confirmer la remise' }}</span></Button>
                    </div>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
